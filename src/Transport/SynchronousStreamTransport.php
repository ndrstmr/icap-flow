<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * You may obtain a copy of the Licence at:
 *
 *     https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 */

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Amp\Cancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapUri;
use Ndrstmr\Icap\OperationDeadline;

/**
 * Blocking transport using plain PHP stream sockets.
 *
 * TLS is explicitly NOT supported here — the synchronous transport is
 * intended for quick CLI / test usage; production deployments should
 * use {@see AsyncAmpTransport} (TLS, streaming, cancellations).
 *
 * Hardened per finding I of the consolidated review:
 *   - connect + read/write timeouts come from Config, not a hard 5 s;
 *   - every branch closes the socket via try/finally;
 *   - the read loop enforces Config::maxResponseSize to defend
 *     against a hostile server sending unbounded bytes.
 *
 * And per finding M09: a chunk goes out completely or the call fails (a
 * short write carries on where it stopped, false is an error), the stream
 * timeout keeps its fraction of a second, and a timeout is an
 * {@see IcapTimeoutException}, not the end of the response. A blocking send()
 * is bounded by the stream timeout on Linux only (on macOS it waits for ever
 * on a peer that stopped reading), so the request goes out through a
 * non-blocking socket that is waited for with stream_select(): a peer that
 * takes nothing for a whole timeout ends the call, on every platform. The
 * response is read in blocking mode, where the stream timeout does apply.
 * Neither wait can be interrupted by the caller's cancellation; it is looked
 * at between them. Use {@see AsyncAmpTransport} where that matters.
 *
 * And per finding M10: the end of the operation ({@see OperationDeadline}, which
 * the client hands it as the cancellation) bounds every native wait to the time
 * that is left, and is looked at between the chunks and after every blocking
 * call. The response has the two limits it has in the async transport
 * ({@see AmpTransportSession}): `responseStartTimeout` to begin, then
 * `streamTimeout` from the first byte, a maximum that no byte starts again. A
 * producer that does not return, or a blocking call that PHP cannot interrupt,
 * is not stopped from outside; the end is looked at when control comes back:
 * after every read, before its bytes, its end of the stream or its failure are
 * told, and once more when the frame is complete (the caller first, then the
 * limit of the transfer).
 */
final class SynchronousStreamTransport implements TransportInterface
{
    private const int READ_CHUNK_SIZE = 8192;

    /** A chunk is written in slices of this size, so that a large one is not copied again for every short write. */
    private const int WRITE_SLICE_SIZE = 65536;

    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): \Amp\Future
    {
        if ($config->getTlsContext() !== null) {
            throw new IcapConnectionException(
                'SynchronousStreamTransport does not support TLS; use AsyncAmpTransport for icaps://.',
            );
        }

        // Cancellation is honoured opportunistically: we check it between
        // the blocking calls (connect, every write, every read). The blocking
        // PHP stream API doesn't allow interrupting a call in flight, so every
        // wait is bounded by a timeout of its own, and by the time that is left
        // of the operation (M10). The async transport gives true cancellation
        // semantics.
        self::look($cancellation);

        $errno = 0;
        $errstr = '';
        $address = IcapUri::tcp($config->host, $config->port);
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            self::connectTimeout($config, $cancellation),
            STREAM_CLIENT_CONNECT,
        );
        if ($stream === false) {
            // The end of the operation may be what cut the attempt short (see connectTimeout()).
            self::look($cancellation);
            throw new IcapConnectionException(
                sprintf('Connection to %s failed: %s', $address, $errstr !== '' ? $errstr : 'unknown error'),
            );
        }

        try {
            // A cancellation that came in while connecting must not reach the producer.
            self::look($cancellation);

            $timeout = $config->getStreamTimeout();
            [$seconds, $microseconds] = self::splitTimeout($timeout);
            stream_set_timeout($stream, $seconds, $microseconds);

            // A blocking send() honours the stream timeout on Linux only, a blocking read
            // everywhere: the request goes out through a non-blocking socket, the response
            // comes in through a blocking one.
            self::setBlocking($stream, false);
            foreach ($rawRequest as $chunk) {
                self::look($cancellation);
                if ($chunk !== '') {
                    self::writeAll($stream, $chunk, $cancellation, $timeout);
                }
            }
            self::setBlocking($stream, true);

            $reader = new ResponseFrameReader(
                maxResponseSize: $config->getMaxResponseSize(),
                maxHeaderLineLength: $config->getMaxHeaderLineLength(),
                maxHeaderCount: $config->getMaxHeaderCount(),
            );
            // Two limits, one after the other: the server's time to begin its answer, then a maximum for the
            // answer to arrive in full. Neither starts again with a byte; each read waits for what is left.
            $limit = $config->getResponseStartTimeout();
            $phaseEnds = hrtime(true) + $limit * 1e9;
            $begun = false;
            $response = $reader->readFrom(
                static function () use ($stream, $cancellation, $timeout, &$limit, &$phaseEnds, &$begun): ?string {
                    $bytes = self::readChunk($stream, $cancellation, $limit, $phaseEnds, $begun ? 'Reading the response' : 'Waiting for the response to begin');
                    if ($bytes !== null && !$begun) {
                        $begun = true;
                        $limit = $timeout;
                        $phaseEnds = hrtime(true) + $limit * 1e9;
                    }

                    return $bytes;
                },
            );

            // Every read waited for what was left of its phase and was looked at when it returned; what is left to look
            // at is the time between the last of them and the complete frame, which decoding it takes. A caller who
            // cancelled in it comes first, whether or not the transfer ran out as well.
            self::look($cancellation);
            if (hrtime(true) >= $phaseEnds) {
                // A frame is complete only after its first byte: the transfer's window is the one that ran out.
                throw self::timeout('Reading the response', $limit);
            }

            return \Amp\Future::complete($response);
        } finally {
            fclose($stream);
        }
    }

    /**
     * How long the connection may take: `socketTimeout`, or what is left of the operation if that is less, and a
     * hair more (10 ms), so that a connect which the end of the operation cuts short fails after that end and is
     * told as it, an {@see IcapTimeoutException}, not as a failed connection; the 10 ms are also what a PHP
     * timeout of 0 would otherwise be.
     */
    private static function connectTimeout(Config $config, ?Cancellation $cancellation): float
    {
        return $cancellation instanceof OperationDeadline
            ? min($config->getSocketTimeout(), $cancellation->remaining() + 0.01)
            : $config->getSocketTimeout();
    }

    /**
     * Looks at the cancellation: the caller's, as the exception it is, and, for an operation of the client, the end
     * of the operation, as an {@see IcapTimeoutException}.
     */
    private static function look(?Cancellation $cancellation): void
    {
        if ($cancellation instanceof OperationDeadline) {
            $cancellation->checkpoint();

            return;
        }

        $cancellation?->throwIfRequested();
    }

    /**
     * PHP takes the stream timeout as whole seconds and microseconds. A value
     * that is not a positive number gives up at once: handed on as it is, a
     * negative one could become a tv_sec of -1, which PHP reads as "wait for
     * ever".
     *
     * @return array{int, int}
     */
    private static function splitTimeout(float $timeout): array
    {
        if (!is_finite($timeout) || $timeout <= 0.0) {
            return [0, 0];
        }

        $seconds = (int) floor($timeout);
        $microseconds = (int) round(($timeout - $seconds) * 1_000_000);

        return $microseconds < 1_000_000 ? [$seconds, $microseconds] : [$seconds + 1, 0];
    }

    /**
     * @param resource $stream
     */
    private static function setBlocking($stream, bool $blocking): void
    {
        if (!stream_set_blocking($stream, $blocking)) {
            throw new IcapConnectionException(
                sprintf('Switching the socket to %s mode failed.', $blocking ? 'blocking' : 'non-blocking'),
            );
        }
    }

    /**
     * Sends one chunk completely or fails, through a non-blocking socket. A
     * write may take less than it is given and then carries on where it
     * stopped; one that takes nothing means the send buffer is full, and the
     * call waits for the peer to read, for one timeout without any progress at
     * most. false ends the call at once.
     *
     * @param resource $stream
     */
    private static function writeAll($stream, string $chunk, ?Cancellation $cancellation, float $timeout): void
    {
        [$seconds, $microseconds] = self::splitTimeout($timeout);
        $window = ($seconds + $microseconds / 1_000_000) * 1e9;
        $length = strlen($chunk);
        $offset = 0;
        $endsAt = null;
        while (true) {
            // PHP raises a notice for every send that fails; the failure is reported below.
            $written = @fwrite(
                $stream,
                $offset === 0 && $length <= self::WRITE_SLICE_SIZE
                    ? $chunk
                    : substr($chunk, $offset, self::WRITE_SLICE_SIZE),
            );
            if ($written === false) {
                throw new IcapConnectionException('Writing the request failed.');
            }

            $offset += $written;
            if ($offset >= $length) {
                return;
            }

            if ($written > 0) {
                $endsAt = null;
            } else {
                $endsAt ??= hrtime(true) + $window;
                self::awaitWritable($stream, $endsAt, $timeout, $cancellation);
            }
            // The rest of the chunk goes out only if the caller still wants it, and the operation still has time.
            self::look($cancellation);
        }
    }

    /**
     * Waits until the socket may take more, or throws once $endsAt has passed.
     * The wait is no longer than the time that is left of the operation. A
     * wait that stream_select() cannot do (a signal interrupted it, or the
     * descriptor lies beyond FD_SETSIZE) is no failure of the request: it
     * returns after a moment and the caller tries to write again.
     *
     * @param resource $stream
     * @param float    $endsAt hrtime(true) at which the wait is over
     */
    private static function awaitWritable($stream, float $endsAt, float $timeout, ?Cancellation $cancellation = null): void
    {
        $left = ($endsAt - hrtime(true)) / 1e9;
        if ($cancellation instanceof OperationDeadline) {
            $left = min($left, $cancellation->remaining());
        }
        if ($left <= 0.0) {
            // The end of the operation, if that is what ran out, is told as such.
            self::look($cancellation);
            throw self::timeout('Writing the request', $timeout);
        }

        $read = null;
        $write = [$stream];
        $except = null;
        [$seconds, $microseconds] = self::splitTimeout($left);
        if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) {
            usleep(1_000);
        }
    }

    /**
     * One read of the response, null at the end of the stream. A read that
     * timed out is no end of the stream (the frame reader would call it a
     * connection closed too early) but a timeout. PHP reports one as false
     * with `timed_out` set; a zero timeout answers with an empty string while
     * the stream has not ended.
     *
     * With $phaseEndsAt (hrtime(true) at which the phase is over) the read waits for what is left of the phase,
     * not for a whole timeout: a maximum, which no byte starts again. It waits no longer than the time that is
     * left of the operation either.
     *
     * @param resource $stream
     */
    private static function readChunk($stream, ?Cancellation $cancellation, float $timeout, ?float $phaseEndsAt = null, string $what = 'Reading the response'): ?string
    {
        self::look($cancellation);

        if ($phaseEndsAt !== null) {
            $left = ($phaseEndsAt - hrtime(true)) / 1e9;
            if ($cancellation instanceof OperationDeadline) {
                $left = min($left, $cancellation->remaining());
            }
            if ($left <= 0.0) {
                self::look($cancellation);
                throw self::timeout($what, $timeout);
            }
            [$seconds, $microseconds] = self::splitTimeout($left);
            stream_set_timeout($stream, $seconds, $microseconds);
        }

        $read = fread($stream, self::READ_CHUNK_SIZE);

        // What the read brought - bytes, the end of the stream, a failure - counts only if the caller did not cancel and
        // neither the operation nor the phase ran out while it blocked: a peer that hung up after that is no framing
        // error, and bytes that came late are no answer.
        self::look($cancellation);
        if ($phaseEndsAt !== null && hrtime(true) >= $phaseEndsAt) {
            throw self::timeout($what, $timeout);
        }

        if ($read !== false && $read !== '') {
            return $read;
        }

        $meta = stream_get_meta_data($stream);
        if ($meta['timed_out'] || ($read === '' && !$meta['eof'])) {
            throw self::timeout($what, $timeout);
        }
        if ($read === false) {
            throw new IcapConnectionException('Reading the response failed.');
        }

        return null;
    }

    private static function timeout(string $what, float $timeout): IcapTimeoutException
    {
        return new IcapTimeoutException(sprintf('%s timed out after %s seconds.', $what, $timeout));
    }
}

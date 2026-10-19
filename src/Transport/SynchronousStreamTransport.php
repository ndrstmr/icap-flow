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
        // PHP stream API doesn't allow interrupting a call in flight, so a
        // hung server is still bounded only by stream_set_timeout(). The
        // async transport gives true cancellation semantics.
        $cancellation?->throwIfRequested();

        $errno = 0;
        $errstr = '';
        $address = IcapUri::tcp($config->host, $config->port);
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $config->getSocketTimeout(),
            STREAM_CLIENT_CONNECT,
        );
        if ($stream === false) {
            throw new IcapConnectionException(
                sprintf('Connection to %s failed: %s', $address, $errstr !== '' ? $errstr : 'unknown error'),
            );
        }

        try {
            // A cancellation that came in while connecting must not reach the producer.
            $cancellation?->throwIfRequested();

            $timeout = $config->getStreamTimeout();
            [$seconds, $microseconds] = self::splitTimeout($timeout);
            stream_set_timeout($stream, $seconds, $microseconds);

            // A blocking send() honours the stream timeout on Linux only, a blocking read
            // everywhere: the request goes out through a non-blocking socket, the response
            // comes in through a blocking one.
            self::setBlocking($stream, false);
            foreach ($rawRequest as $chunk) {
                $cancellation?->throwIfRequested();
                if ($chunk !== '') {
                    self::writeAll($stream, $chunk, $cancellation, $timeout);
                }
            }
            self::setBlocking($stream, true);

            $reader = new ResponseFrameReader(
                maxResponseSize: $config->getMaxResponseSize(),
                maxHeaderLineLength: $config->getMaxHeaderLineLength(),
            );
            $response = $reader->readFrom(
                static fn (): ?string => self::readChunk($stream, $cancellation, $timeout),
            );

            return \Amp\Future::complete($response);
        } finally {
            fclose($stream);
        }
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
                self::awaitWritable($stream, $endsAt, $timeout);
            }
            // The rest of the chunk goes out only if the caller still wants it.
            $cancellation?->throwIfRequested();
        }
    }

    /**
     * Waits until the socket may take more, or throws once $endsAt has passed.
     * A wait that stream_select() cannot do (a signal interrupted it, or the
     * descriptor lies beyond FD_SETSIZE) is no failure of the request: it
     * returns after a moment and the caller tries to write again.
     *
     * @param resource $stream
     * @param float    $endsAt hrtime(true) at which the wait is over
     */
    private static function awaitWritable($stream, float $endsAt, float $timeout): void
    {
        $left = ($endsAt - hrtime(true)) / 1e9;
        if ($left <= 0.0) {
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
     * @param resource $stream
     */
    private static function readChunk($stream, ?Cancellation $cancellation, float $timeout): ?string
    {
        $cancellation?->throwIfRequested();

        $read = fread($stream, self::READ_CHUNK_SIZE);
        if ($read !== false && $read !== '') {
            return $read;
        }

        $meta = stream_get_meta_data($stream);
        if ($meta['timed_out'] || ($read === '' && !$meta['eof'])) {
            throw self::timeout('Reading the response', $timeout);
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

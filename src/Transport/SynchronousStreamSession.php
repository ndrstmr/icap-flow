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
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapUri;
use Ndrstmr\Icap\OperationDeadline;

/**
 * One TCP connection of the {@see SynchronousStreamTransport}, for as many write/read round trips as an exchange
 * needs: the preview and the rest of the body of RFC 3507 §4.5 travel on it as one request. Calls block, and there is
 * no TLS; use {@see AsyncAmpTransport} for icaps://.
 *
 * A request goes out through a non-blocking socket that is waited for with stream_select(): a blocking send() waits for
 * ever on macOS for a peer that has stopped reading. A chunk is sent completely or the call fails. A {@see write()} has
 * one window of `streamTimeout` for all its chunks, the time its producer takes included, as in
 * {@see AmpTransportSession}; the request of {@see SynchronousStreamTransport::request()}, which has the connection to
 * itself, keeps the limit of that transport ({@see sendRequest()}). A response is read in blocking mode in the two
 * windows of {@see AmpTransportSession}: `responseStartTimeout` for the server to begin, then `streamTimeout` from its
 * first byte as a maximum for the response to arrive in full.
 *
 * A native wait cannot be interrupted by the caller's cancellation, which in an operation of the client is also its end
 * ({@see OperationDeadline}). Each wait is bounded by the time that is left of the operation and of its own limit, and
 * both are looked at between the waits: before the producer is asked for a first chunk, whenever it hands one back,
 * after every write and every wait before the result counts, and after every read before its bytes, its end of the
 * stream or its failure are told. A producer that does not return, or a call that PHP cannot interrupt, is noticed when
 * control comes back. A call that does not finish closes the session for good; a limit that passes is an
 * {@see IcapTimeoutException}, a cancellation of the caller stays what it is and comes first.
 *
 * A read that brings in bytes behind the end of a response fails, as in {@see AmpTransportSession}: they answer no
 * request.
 */
final class SynchronousStreamSession implements DiscardingTransportSession
{
    private const int READ_CHUNK_SIZE = 8192;

    /** A chunk is written in slices of this size, so that a large one is not copied again for every short write. */
    private const int WRITE_SLICE_SIZE = 65536;

    private bool $disposed = false;

    private readonly ResponseFrameReader $reader;

    /**
     * @param resource $stream
     */
    private function __construct(
        private readonly Config $config,
        private $stream,
        private readonly ?Cancellation $cancellation,
    ) {
        $this->reader = new ResponseFrameReader(
            maxResponseSize: $config->getMaxResponseSize(),
            maxHeaderLineLength: $config->getMaxHeaderLineLength(),
            maxHeaderCount: $config->getMaxHeaderCount(),
        );
    }

    /**
     * @throws IcapConnectionException When a TLS context is configured, or the connection fails
     */
    public static function open(Config $config, ?Cancellation $cancellation): self
    {
        if ($config->getTlsContext() !== null) {
            throw new IcapConnectionException(
                'SynchronousStreamTransport does not support TLS; use AsyncAmpTransport for icaps://.',
            );
        }

        // The blocking PHP stream API does not allow interrupting a call in flight, so the cancellation is looked at
        // between the calls, and every wait is bounded by a timeout of its own and by what is left of the operation.
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

            [$seconds, $microseconds] = self::splitTimeout($config->getStreamTimeout());
            stream_set_timeout($stream, $seconds, $microseconds);
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }

        return new self($config, $stream, $cancellation);
    }

    /**
     * All chunks within one window of `streamTimeout`, the time the producer takes between them and after the last one
     * included ({@see TransportSession::write()}).
     */
    #[\Override]
    public function write(iterable $chunks): void
    {
        $this->send($chunks, hrtime(true) + $this->config->getStreamTimeout() * 1e9);
    }

    /**
     * The request of {@see SynchronousStreamTransport::request()}, which has the connection to itself, with the limit
     * of that transport: `streamTimeout` is the time the peer may go without taking a byte, and every byte it takes
     * starts it again. The end of the operation bounds the total.
     *
     * @internal
     *
     * @param iterable<string> $chunks
     */
    public function sendRequest(iterable $chunks): void
    {
        $this->send($chunks, null);
    }

    /**
     * @param iterable<string> $chunks
     * @param float|null       $windowEndsAt hrtime(true) at which the window of the call is over; null for the limit
     *                                       of {@see sendRequest()}
     */
    private function send(iterable $chunks, ?float $windowEndsAt): void
    {
        $this->assertActive();

        try {
            $timeout = $this->config->getStreamTimeout();
            // Cancelled or out of time before it began: the producer is not asked for anything.
            self::lookAt($this->cancellation, $windowEndsAt, $timeout);
            // A blocking send() honours the stream timeout on Linux only, a blocking read everywhere: the request goes
            // out through a non-blocking socket, the response comes in through a blocking one.
            self::setBlocking($this->stream, false);
            foreach ($chunks as $chunk) {
                // The producer has handed control back. writeAll() looks after every write, so the producer is asked
                // for the next chunk only while the call may go on.
                self::lookAt($this->cancellation, $windowEndsAt, $timeout);
                if ($chunk !== '') {
                    self::writeAll($this->stream, $chunk, $this->cancellation, $timeout, $windowEndsAt);
                }
            }
            // The producer may have run on after the last chunk went out.
            self::lookAt($this->cancellation, $windowEndsAt, $timeout);
            self::setBlocking($this->stream, true);
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    #[\Override]
    public function readResponse(): string
    {
        return $this->receive(static fn (ResponseFrameReader $reader, \Closure $produce): string => $reader->readFrom($produce));
    }

    #[\Override]
    public function readResponseDiscardingBody(int $maxResponseBytes): IcapResponse
    {
        return $this->receive(static fn (ResponseFrameReader $reader, \Closure $produce): IcapResponse => $reader->readDiscardingBody($produce, $maxResponseBytes));
    }

    /**
     * One response, in two limits one after the other: the server's time to begin its answer, then a maximum for the
     * answer to arrive in full. Neither starts again with a byte; each read waits for what is left.
     *
     * @template T
     *
     * @param \Closure(ResponseFrameReader, \Closure(): ?string): T $consume
     *
     * @return T
     */
    private function receive(\Closure $consume): mixed
    {
        $this->assertActive();

        try {
            $stream = $this->stream;
            $cancellation = $this->cancellation;
            $timeout = $this->config->getStreamTimeout();
            $begun = false;
            $limit = $this->config->getResponseStartTimeout();
            $phaseEnds = hrtime(true) + $limit * 1e9;
            $response = $consume(
                $this->reader,
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
            // at is the time between the last of them and the complete response, which decoding it takes. A caller who
            // cancelled in it comes first, whether or not the transfer ran out as well.
            self::look($cancellation);
            if (hrtime(true) >= $phaseEnds) {
                // A response is complete only after its first byte: the transfer's window is the one that ran out.
                throw self::timeout('Reading the response', $limit);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * There is no pool: the connection ends with the exchange.
     */
    #[\Override]
    public function release(): void
    {
        $this->close();
    }

    #[\Override]
    public function close(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        fclose($this->stream);
    }

    private function assertActive(): void
    {
        if ($this->disposed) {
            throw new \LogicException('TransportSession has already been released or closed.');
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
     * Looks at the cancellation, then at the window of a write if it has one.
     */
    private static function lookAt(?Cancellation $cancellation, ?float $windowEndsAt, float $timeout): void
    {
        self::look($cancellation);
        if ($windowEndsAt !== null && hrtime(true) >= $windowEndsAt) {
            throw self::timeout('Writing the request', $timeout);
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
     * call waits for the peer to read: until the end of the window of the
     * call ($windowEndsAt, hrtime(true)), or without a window for one timeout
     * without any progress at most. false ends the call at once.
     *
     * @param resource $stream
     */
    private static function writeAll($stream, string $chunk, ?Cancellation $cancellation, float $timeout, ?float $windowEndsAt = null): void
    {
        [$seconds, $microseconds] = self::splitTimeout($timeout);
        $stall = ($seconds + $microseconds / 1_000_000) * 1e9;
        $length = strlen($chunk);
        $offset = 0;
        $endsAt = $windowEndsAt;
        while (true) {
            // PHP raises a notice for every send that fails; the failure is reported below.
            $written = @fwrite(
                $stream,
                $offset === 0 && $length <= self::WRITE_SLICE_SIZE
                    ? $chunk
                    : substr($chunk, $offset, self::WRITE_SLICE_SIZE),
            );
            // What the write did, a failure too, counts only if the caller has not cancelled and neither the operation
            // nor the window ran out meanwhile.
            self::lookAt($cancellation, $windowEndsAt, $timeout);
            if ($written === false) {
                throw new IcapConnectionException('Writing the request failed.');
            }

            $offset += $written;
            if ($offset >= $length) {
                return;
            }

            if ($written > 0) {
                // Progress starts the time without progress again, not the window.
                $endsAt = $windowEndsAt;
            } else {
                $endsAt ??= hrtime(true) + $stall;
                self::awaitWritable($stream, $endsAt, $timeout, $cancellation);
                // The rest of the chunk goes out only if the caller still wants it, and the operation and the window
                // still have time.
                self::lookAt($cancellation, $windowEndsAt, $timeout);
            }
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

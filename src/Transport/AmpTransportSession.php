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

use Amp\ByteStream\ClosedException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Revolt\EventLoop;

/**
 * One-acquired-socket binding used for multi-round-trip flows like
 * the strict RFC 3507 §4.5 preview-continue exchange.
 *
 * Lifecycle:
 *   - {@see AsyncAmpTransport::openSession()} acquires a socket from
 *     the pool (or opens a fresh one).
 *   - The caller writes / reads as many times as the protocol
 *     requires.
 *   - The caller calls {@see release()} on success (returns to the
 *     pool when one is configured) or {@see close()} on failure.
 *
 * Every {@see write()} call gets a window of `streamTimeout` seconds of its own,
 * shared by everything that call does — not one window per chunk, and no timer
 * that spans the session. A {@see readResponse()} call has two, one after the
 * other (M10): `responseStartTimeout` for the server to begin its answer (the
 * time a scan needs), then `streamTimeout` from the first byte for the answer
 * to arrive in full, a maximum that no byte starts again. A call also ends with
 * the caller's cancellation, which in an operation of the client is also the end
 * of the operation ({@see \Ndrstmr\Icap\OperationDeadline}). A call that ends in
 * either, or in any other failure, closes the session for good: a request
 * sent in part or a response read in part cannot be continued. {@see release()}
 * does not hand the socket to a pool once the caller has cancelled or the
 * operation has run out of time either. The window passing is an
 * {@see IcapTimeoutException}; the caller's cancellation — also a
 * `TimeoutCancellation` — stays an `Amp\CancelledException`.
 *
 * The producer of a request is part of the call. The event loop cannot fire
 * the timer of the window while a producer holds it, so the window also has an
 * end on the monotonic clock, and {@see write()} looks at the caller, the
 * window and that end wherever the producer hands control back: with every
 * chunk (an empty one too), after every write and before it returns. A
 * producer that never hands control back cannot be interrupted from outside.
 *
 * The session owns the bytes it has read. A read may bring more than one response
 * needs; what lies behind the end of a response stays in the session's reader and is
 * never dropped and never taken for something else. Only another {@see readResponse()}
 * uses it. A request cannot be written on top of it, and a socket that holds it is
 * not given back to the pool: no request is waiting for those bytes, so they are not
 * an answer to the next one.
 */
final class AmpTransportSession implements TransportSession
{
    private bool $disposed = false;

    private readonly ResponseFrameReader $reader;

    public function __construct(
        private readonly Config $config,
        private readonly SocketInterface $socket,
        private readonly float $streamTimeout,
        private readonly ?Cancellation $userCancellation,
        int $maxResponseSize,
        int $maxHeaderLineLength,
        private readonly ?ConnectionPoolInterface $pool,
    ) {
        $this->reader = new ResponseFrameReader(
            maxResponseSize: $maxResponseSize,
            maxHeaderLineLength: $maxHeaderLineLength,
            maxHeaderCount: $config->getMaxHeaderCount(),
        );
    }

    #[\Override]
    public function write(iterable $chunks): void
    {
        $this->assertActive();
        if ($this->reader->hasPendingBytes()) {
            $this->close();
            throw new IcapMalformedResponseException('The server sent bytes that no request was waiting for.');
        }

        $this->inWindow('Writing the request', 'stream timeout', $this->streamTimeout, function (Cancellation $window, \Closure $checkpoint) use ($chunks): void {
            // Socket::write() takes no cancellation: against a peer that does not read it
            // suspends this fiber until the peer does. Closing the socket is what fails the
            // pending write, so the end of the window, by deadline or by the caller, closes it.
            $watch = $window->subscribe(function (): void {
                $this->close();
            });

            try {
                foreach ($chunks as $chunk) {
                    // The producer has handed control back; an empty chunk is no exception, no
                    // write would fail for it.
                    $checkpoint();
                    if ($chunk !== '') {
                        $this->socket->write($chunk);
                    }
                    // Before the producer is asked for the next chunk.
                    $checkpoint();
                }

                // The producer may have run on after the last chunk went out.
                $checkpoint();
            } finally {
                $window->unsubscribe($watch);
            }
        });
    }

    #[\Override]
    public function readResponse(): string
    {
        $this->assertActive();
        $reader = $this->reader;
        $socket = $this->socket;
        $streamTimeout = $this->streamTimeout;
        // Bytes of the answer that are in already: it has begun, and only its transfer has a limit left.
        $begun = $reader->hasPendingBytes();

        return $this->inWindow(
            $begun ? 'Reading the response' : 'Waiting for the response to begin',
            $begun ? 'stream timeout' : 'response start timeout',
            $begun ? $streamTimeout : $this->config->getResponseStartTimeout(),
            static function (Cancellation $window, \Closure $checkpoint, \Closure $next) use ($reader, $socket, $streamTimeout, &$begun): string {
                $frame = $reader->readFrom(static function () use ($socket, $window, $checkpoint, $next, $streamTimeout, &$begun): ?string {
                    // No read is begun for a caller who has cancelled, nor once the stretch has run out.
                    $checkpoint();
                    $bytes = $socket->read($window);
                    // The read may have found its bytes without waiting, or been held up by something that holds the
                    // event loop. Bytes or the end of the stream count only if the caller has not cancelled and the
                    // stretch the read began in has not run out (for the first bytes: the time to begin): otherwise the
                    // decoder gets neither, and the end of the stream would be a framing error.
                    $checkpoint();
                    if ($bytes !== null && !$begun) {
                        // The first byte is in: the wait for the server is over, and the answer has to arrive within the
                        // stream timeout from here, whatever its further bytes do.
                        $begun = true;
                        $next('Reading the response', 'stream timeout', $streamTimeout);
                    }

                    return $bytes;
                });
                // The frame is complete. A timer cannot say that its window has passed while the bytes were decoded, or
                // while the caller cancelled: the clock and the caller are looked at before the frame is returned.
                $checkpoint();

                return $frame;
            },
        );
    }

    /**
     * Runs one IO call in a window of its own, so the time a scan takes after `100 Continue` does not eat
     * into the time the preview phase had. A call that does not finish leaves a request or a response in
     * part, and the session is closed for good; whatever ended it is named (the caller's cancellation as the
     * `Amp\CancelledException` it is, only the window passing as an {@see IcapTimeoutException}). Timer and
     * subscription are removed on every way out, not left to destructors: an exception that is still
     * referenced keeps the arguments of its stack frames alive.
     *
     * The phase gets the cancellation of the window and a checkpoint for the places where it has control
     * back from something that does not look by itself: a producer, or a read that returned without the
     * timer having had a turn of the event loop (it found its bytes in the socket, or something held the
     * loop up). The checkpoint throws the caller's cancellation, or that of the window once it has passed:
     * the monotonic clock says so when the timer cannot.
     *
     * The phase can go on into a second stretch with a limit of its own: the third argument it is handed
     * replaces the timer and the end on the clock ($what and $limit are for the message), and the window
     * stays the one the call and its cancellation belong to. The checkpoint looks at the stretch that runs,
     * so the first bytes of an answer are measured against the time to begin before the window of the
     * transfer takes its place.
     *
     * @template T
     *
     * @param string                                                                         $what    what the call does, for the message
     * @param string                                                                         $limit   the name of the limit that applies, for the message
     * @param \Closure(Cancellation, \Closure(): void, \Closure(string, string, float): void): T $phase
     *
     * @return T
     */
    private function inWindow(string $what, string $limit, float $seconds, \Closure $phase): mixed
    {
        $caller = $this->userCancellation;
        $window = new DeferredCancellation();
        $expired = false;
        $deadline = null;
        $watch = null;
        // The end of the stretch that runs, on the monotonic clock: $arm sets it together with the timer.
        $endsAt = 0.0;
        $checkpoint = static function () use ($caller, $window, &$endsAt, &$expired): void {
            $caller?->throwIfRequested();
            if (hrtime(true) >= $endsAt) {
                $expired = true;
                $window->cancel();
            }
            $window->getCancellation()->throwIfRequested();
        };
        $arm = static function (string $newWhat, string $newLimit, float $newSeconds) use (&$what, &$limit, &$seconds, &$endsAt, &$deadline, &$expired, $window): void {
            if ($deadline !== null) {
                EventLoop::cancel($deadline);
            }
            $what = $newWhat;
            $limit = $newLimit;
            $seconds = $newSeconds;
            // The end on the clock moves with the timer: the checkpoint looks at the stretch that is running.
            $endsAt = hrtime(true) + $newSeconds * 1e9;
            $deadline = EventLoop::delay($newSeconds, static function () use (&$expired, $window): void {
                $expired = true;
                $window->cancel();
            });
        };

        try {
            // Cancelled before it began: nothing is pulled, sent or read.
            $caller?->throwIfRequested();

            $arm($what, $limit, $seconds);
            $watch = $caller?->subscribe(static function () use ($window): void {
                $window->cancel();
            });

            return $phase($window->getCancellation(), $checkpoint, $arm);
        } catch (\Throwable $e) {
            $this->close();

            // A socket closed under a pending write reports a closed stream, a cancelled
            // window a cancellation: say which of the two ended the call.
            if ($e instanceof ClosedException || $e instanceof CancelledException) {
                $caller?->throwIfRequested();
                if ($expired) {
                    throw new IcapTimeoutException(sprintf(
                        '%s did not finish within the %s of %s seconds.',
                        $what,
                        $limit,
                        $seconds,
                    ));
                }
            }

            throw $e;
        } finally {
            if ($deadline !== null) {
                EventLoop::cancel($deadline);
            }
            if ($caller !== null && $watch !== null) {
                $caller->unsubscribe($watch);
            }
        }
    }

    #[\Override]
    public function release(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        // Bytes nobody asked for are on this socket: the next user would read them as its answer. And an operation
        // that was cancelled, or has run out of time, hands nothing to the pool, whatever the exchange looked like
        // while it lasted (M10).
        if ($this->pool !== null && !$this->reader->hasPendingBytes() && !($this->userCancellation?->isRequested() ?? false)) {
            $this->pool->release($this->config, $this->socket);
            return;
        }
        $this->socket->close();
    }

    #[\Override]
    public function close(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $this->socket->close();
    }

    private function assertActive(): void
    {
        if ($this->disposed) {
            throw new \LogicException('TransportSession has already been released or closed.');
        }
    }
}

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
 * Every {@see write()} and every {@see readResponse()} call gets a window
 * of `streamTimeout` seconds of its own, shared by everything that call
 * does — not one window per chunk, and no timer that spans the session.
 * The call also ends with the caller's cancellation. A call that ends in
 * either, or in any other failure, closes the session for good: a request
 * sent in part or a response read in part cannot be continued, and
 * {@see release()} afterwards cannot hand the socket to a pool. The
 * window passing is an {@see IcapTimeoutException}; the caller's
 * cancellation — also one that is a `TimeoutCancellation` — stays the
 * `Amp\CancelledException` it is.
 *
 * The producer of a request is part of the call. The event loop cannot fire
 * the timer of the window while a producer holds it, so the window also has an
 * end on the monotonic clock, and {@see write()} looks at the caller, the
 * window and that end wherever the producer hands control back: with every
 * chunk (an empty one too), after every write and before it returns. A
 * producer that never hands control back cannot be interrupted from outside.
 */
final class AmpTransportSession implements TransportSession
{
    private bool $disposed = false;

    public function __construct(
        private readonly Config $config,
        private readonly SocketInterface $socket,
        private readonly float $streamTimeout,
        private readonly ?Cancellation $userCancellation,
        private readonly int $maxResponseSize,
        private readonly int $maxHeaderLineLength,
        private readonly ?ConnectionPoolInterface $pool,
    ) {
    }

    #[\Override]
    public function write(iterable $chunks): void
    {
        $this->assertActive();

        $this->inWindow('Writing the request', function (Cancellation $window, \Closure $checkpoint) use ($chunks): void {
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
        $reader = new ResponseFrameReader(
            maxResponseSize: $this->maxResponseSize,
            maxHeaderLineLength: $this->maxHeaderLineLength,
        );
        $socket = $this->socket;

        return $this->inWindow(
            'Reading the response',
            static fn (Cancellation $window): string => $reader->readFrom(
                static fn (): ?string => $socket->read($window),
            ),
        );
    }

    /**
     * Runs one IO call in a window of its own: `streamTimeout` seconds for everything the call
     * does, so the time a scan takes after `100 Continue` does not eat into the time the
     * preview phase had. The window also ends with the caller's cancellation.
     *
     * A call that does not finish leaves a request sent in part or a response read in part,
     * and with it a socket no other exchange may use: the session is closed for good, and
     * whatever ended the call is named. The caller's cancellation, also a `TimeoutCancellation`
     * of theirs, is thrown as the `Amp\CancelledException` it is; only the window passing is an
     * {@see IcapTimeoutException}. Timer and subscription are removed on every way out, not
     * left to destructors: an exception that is still referenced keeps the arguments of its
     * stack frames alive.
     *
     * The phase gets the cancellation of the window and a checkpoint for the places where it
     * has control back from something that does not look by itself, a producer: it throws the
     * caller's cancellation, or the cancellation of the window once the window has passed. The
     * timer cannot say so while that something holds the event loop, the monotonic clock can.
     *
     * @template T
     *
     * @param \Closure(Cancellation, \Closure(): void): T $phase
     *
     * @return T
     */
    private function inWindow(string $what, \Closure $phase): mixed
    {
        $caller = $this->userCancellation;
        $window = new DeferredCancellation();
        $expired = false;
        $deadline = null;
        $watch = null;
        $endsAt = hrtime(true) + $this->streamTimeout * 1e9;
        $checkpoint = static function () use ($caller, $window, $endsAt, &$expired): void {
            $caller?->throwIfRequested();
            if (hrtime(true) >= $endsAt) {
                $expired = true;
                $window->cancel();
            }
            $window->getCancellation()->throwIfRequested();
        };

        try {
            // Cancelled before it began: nothing is pulled, sent or read.
            $caller?->throwIfRequested();

            $deadline = EventLoop::delay($this->streamTimeout, function () use (&$expired, $window): void {
                $expired = true;
                $window->cancel();
            });
            $watch = $caller?->subscribe(static function () use ($window): void {
                $window->cancel();
            });

            return $phase($window->getCancellation(), $checkpoint);
        } catch (\Throwable $e) {
            $this->close();

            // A socket closed under a pending write reports a closed stream, a cancelled
            // window a cancellation: say which of the two ended the call.
            if ($e instanceof ClosedException || $e instanceof CancelledException) {
                $caller?->throwIfRequested();
                if ($expired) {
                    throw new IcapTimeoutException(sprintf(
                        '%s did not finish within the stream timeout of %s seconds.',
                        $what,
                        $this->streamTimeout,
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

        if ($this->pool !== null) {
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

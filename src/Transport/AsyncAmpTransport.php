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
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Revolt\EventLoop;

use function Amp\async;

/**
 * Asynchronous transport implementation using amphp/socket.
 *
 * Upgrades to TLS (icaps://) automatically when the supplied Config
 * carries a {@see \Amp\Socket\ClientTlsContext}; otherwise connects
 * plain tcp://. The response is bounded by Config::maxResponseSize to
 * keep a hostile server from exhausting the client's memory.
 *
 * The optional user-supplied {@see Cancellation} is combined with the
 * transport's own `streamTimeout` window for each phase (acquiring the
 * socket, writing the request, reading the response); whichever comes
 * first ends the phase. The caller's cancellation — also one that is a
 * `TimeoutCancellation` — ends it with `Amp\CancelledException`, the
 * transport's own window with {@see IcapTimeoutException}.
 *
 * Response framing is done by {@see ResponseFrameReader} so the read
 * loop terminates as soon as the message is complete — no dependency
 * on the server closing the socket.
 *
 * **Connection pooling (v2.1+).** When constructed with a
 * {@see ConnectionPoolInterface}, the transport calls `acquire()`
 * instead of opening a fresh TCP/TLS connection. A socket goes back
 * with `release()` only when the caller of a {@see openSession()}
 * session has validated the whole exchange; every other way out closes
 * it. {@see request()} returns the raw response string, before any
 * status or verdict has been checked, so it always closes the socket.
 * Without a pool the transport opens a fresh socket per request and
 * closes it after — identical to the v2.0 behaviour. A pool that limits
 * its connections ({@see AmpConnectionPool}) limits the sessions and
 * requests of this transport too: a session is a connection, and its room
 * is back when the socket is closed or released. Without a pool the
 * application limits its own concurrency.
 *
 * **Sessions (v2.1+).** Implements {@see SessionAwareTransport} so
 * callers that need to send + receive multiple times on the same
 * connection (strict RFC 3507 §4.5 preview-continue, ICAP request
 * pipelining, …) can call {@see openSession()}.
 */
final class AsyncAmpTransport implements SessionAwareTransport
{
    public function __construct(
        private ?ConnectionPoolInterface $pool = null,
    ) {
    }

    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): \Amp\Future
    {
        /** @var \Amp\Future<string> $future */
        $future = async(function () use ($config, $rawRequest, $cancellation): string {
            $session = $this->openSession($config, $cancellation);
            try {
                $session->write($rawRequest);

                return $session->readResponse();
            } finally {
                // The caller gets a string no one has validated yet: a
                // 100 Continue, an error status or a response with bytes
                // behind it are indistinguishable from a clean answer
                // here. Reuse needs a validated exchange, which only a
                // session offers.
                $session->close();
            }
        });

        return $future;
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession
    {
        // Cancelled before it began: neither the pool nor the connector is asked.
        $cancellation?->throwIfRequested();

        // Waiting for the pool, connecting and the TLS handshake share one window: streamTimeout seconds, or the
        // handshake limit if that is longer (the window must not cut it short). The end of the whole operation reaches
        // the window as the cancellation of the caller and is shorter if it is. Timer and subscription are removed on
        // every way out, not left to destructors: an exception that is still referenced keeps the arguments of its
        // stack frames, and with them the cancellation, alive.
        $setup = $config->getTlsContext() === null
            ? $config->getStreamTimeout()
            : max($config->getStreamTimeout(), $config->getTlsHandshakeTimeout());
        $endsAt = hrtime(true) + $setup * 1e9;
        $window = new DeferredCancellation();
        $expired = false;
        $deadline = EventLoop::delay($setup, function () use (&$expired, $window): void {
            $expired = true;
            $window->cancel();
        });
        $watch = $cancellation?->subscribe(static function () use ($window): void {
            $window->cancel();
        });
        $socket = null;

        try {
            $socket = $this->acquireSocket($config, $window->getCancellation());

            // A connector need not honour the token it was given, and one that holds the event
            // loop leaves the timer no chance to fire. A socket it hands back after the caller
            // gave up or the window passed is not used.
            $cancellation?->throwIfRequested();
            if (hrtime(true) >= $endsAt) {
                $expired = true;
                $window->cancel();
            }
            $window->getCancellation()->throwIfRequested();
        } catch (\Throwable $e) {
            $socket?->close();

            if ($e instanceof CancelledException) {
                // The caller's cancellation stays what it is; only the transport's own
                // window passing becomes an IcapTimeoutException.
                $cancellation?->throwIfRequested();
                if ($expired) {
                    throw new IcapTimeoutException(sprintf(
                        'No connection was set up within %s seconds.',
                        $setup,
                    ));
                }
            }

            throw $e;
        } finally {
            EventLoop::cancel($deadline);
            if ($cancellation !== null && $watch !== null) {
                $cancellation->unsubscribe($watch);
            }
        }

        // The session gives each IO call (write / readResponse) the full
        // streamTimeout window — no session-lifetime timer.
        return new AmpTransportSession(
            config: $config,
            socket: $socket,
            streamTimeout: $config->getStreamTimeout(),
            userCancellation: $cancellation,
            maxResponseSize: $config->getMaxResponseSize(),
            maxHeaderLineLength: $config->getMaxHeaderLineLength(),
            pool: $this->pool,
        );
    }

    private function acquireSocket(Config $config, Cancellation $cancellation): SocketInterface
    {
        if ($this->pool !== null) {
            return $this->pool->acquire($config, $cancellation);
        }

        return SocketOpener::open($config, $cancellation);
    }
}

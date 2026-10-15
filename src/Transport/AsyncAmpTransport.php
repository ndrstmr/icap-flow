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
use Amp\CompositeCancellation;
use Amp\Socket;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket as SocketInterface;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\IcapUri;

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
 * transport's internal {@see TimeoutCancellation} via a
 * {@see CompositeCancellation}; whichever fires first aborts the
 * read/write loop with `Amp\CancelledException`.
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
 * closes it after — identical to the v2.0 behaviour.
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
                // 100 Continue, an error status or bytes the framing
                // reader dropped are indistinguishable from a clean
                // answer here. Reuse needs a validated exchange, which
                // only a session offers.
                $session->close();
            }
        });

        return $future;
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession
    {
        // Use a one-shot timeout for socket acquisition only.
        $acquireCancellation = new TimeoutCancellation($config->getStreamTimeout());
        $effectiveAcquire = $cancellation === null
            ? $acquireCancellation
            : new CompositeCancellation($cancellation, $acquireCancellation);

        $socket = $this->acquireSocket($config, $effectiveAcquire);

        // The session creates a fresh TimeoutCancellation per IO call
        // (write / readResponse), so each individual operation gets
        // the full streamTimeout window — no session-lifetime timer.
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

        $tls = $config->getTlsContext();
        $url = IcapUri::tcp($config->host, $config->port);
        $context = (new ConnectContext())->withConnectTimeout($config->getSocketTimeout());
        if ($tls !== null) {
            $context = $context->withTlsContext($tls);
        }

        try {
            if ($tls !== null) {
                return Socket\connectTls($url, $context, $cancellation);
            }
            return Socket\connect($url, $context, $cancellation);
        } catch (Socket\ConnectException $e) {
            throw new IcapConnectionException(
                sprintf('Async connection to %s:%d failed.', $config->host, $config->port),
                0,
                $e,
            );
        }
    }
}

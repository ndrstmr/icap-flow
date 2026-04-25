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
 * {@see ConnectionPoolInterface}, the transport calls
 * `acquire()` instead of opening a fresh TCP/TLS connection and
 * `release()` instead of closing the socket. The socket is closed
 * (rather than returned) when:
 *   - the framing reader threw — the socket might be in an
 *     inconsistent state and we play safe;
 *   - the server's response carries `Connection: close` (RFC 7230
 *     §6.1) — we honour the close intent.
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
            $closeForced = false;
            try {
                $session->write($rawRequest);
                $response = $session->readResponse();
                if ($this->serverWantsClose($response)) {
                    $closeForced = true;
                }
                return $response;
            } catch (\Throwable $e) {
                $closeForced = true;
                throw $e;
            } finally {
                if ($closeForced) {
                    $session->close();
                } else {
                    $session->release();
                }
            }
        });

        return $future;
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession
    {
        $timeoutCancellation = new TimeoutCancellation($config->getStreamTimeout());
        $effective = $cancellation === null
            ? $timeoutCancellation
            : new CompositeCancellation($cancellation, $timeoutCancellation);

        $socket = $this->acquireSocket($config, $effective);

        return new AmpTransportSession(
            config: $config,
            socket: $socket,
            cancellation: $effective,
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
        $url = sprintf('tcp://%s:%d', $config->host, $config->port);
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

    /**
     * Cheap heuristic for `Connection: close` in the ICAP head. We
     * don't fully reparse here — the response parser does that — but
     * we want to keep the socket-disposal decision local to the
     * transport.
     */
    private function serverWantsClose(string $response): bool
    {
        $headEnd = strpos($response, "\r\n\r\n");
        $head = $headEnd === false ? $response : substr($response, 0, $headEnd);
        return preg_match('/^Connection:\s*close\s*$/im', $head) === 1;
    }
}

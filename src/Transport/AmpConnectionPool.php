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
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket as SocketInterface;
use Closure;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\HeaderFields;
use Ndrstmr\Icap\IcapUri;

/**
 * In-process keep-alive pool of amphp sockets.
 *
 * Idle sockets are stored as a LIFO stack per host:port[:tls] key
 * (most recently used first — warmer connections are likelier to
 * still be alive). On {@see acquire()} the pool pops sockets off the
 * stack, drops any that are already closed, and connects a fresh one
 * if none are usable. On {@see release()} a socket is pushed back
 * unless the per-host cap is reached, in which case it's closed.
 *
 * Each entry records when it became idle. On {@see acquire()} entries
 * older than `$maxIdleSeconds` (default 30 s) are evicted and closed
 * before testing `isClosed()`. This prevents stale socket
 * accumulation in long-running PHP workers (Swoole, RoadRunner,
 * ReactPHP) where the server may have closed its end silently.
 */
final class AmpConnectionPool implements ConnectionPoolInterface
{
    /** @var array<string, list<array{socket: SocketInterface, idleSince: float}>> */
    private array $idle = [];

    private bool $closed = false;

    /** @var \WeakMap<ClientTlsContext, int> */
    private \WeakMap $tlsTokens;

    private int $lastTlsToken = 0;

    /** @var Closure(Config, ?Cancellation): SocketInterface */
    private Closure $connector;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param int                                                                  $maxConnectionsPerHost  cap on idle sockets per host:port[:tls] key
     * @param (Closure(Config, ?Cancellation): SocketInterface)|null               $connector              optional override — production code uses the amphp connector; tests can inject pre-built socket pairs
     * @param int|null                                                             $serverMaxConnections   optional server-advertised Max-Connections (RFC 3507 §4.10.2); when set, the effective idle cap becomes min(localCap, serverMax)
     * @param float                                                                $maxIdleSeconds         idle sockets older than this are evicted on acquire() (default 30 s)
     * @param (Closure(): float)|null                                              $clock                  monotonic clock for idle-age checks; defaults to microtime(true); injectable for deterministic tests
     */
    public function __construct(
        private int $maxConnectionsPerHost = 8,
        ?Closure $connector = null,
        private ?int $serverMaxConnections = null,
        private float $maxIdleSeconds = 30.0,
        ?Closure $clock = null,
    ) {
        if ($maxConnectionsPerHost < 1) {
            throw new \InvalidArgumentException(
                'maxConnectionsPerHost must be >= 1, got: ' . $maxConnectionsPerHost,
            );
        }

        if ($serverMaxConnections !== null && $serverMaxConnections < 1) {
            throw new \InvalidArgumentException(
                'serverMaxConnections must be >= 1, got: ' . $serverMaxConnections,
            );
        }

        if ($maxIdleSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'maxIdleSeconds must be > 0, got: ' . $maxIdleSeconds,
            );
        }

        $this->connector = $connector ?? self::defaultConnector();
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->tlsTokens = new \WeakMap();
    }

    #[\Override]
    public function acquire(Config $config, ?Cancellation $cancellation = null): SocketInterface
    {
        if ($this->closed) {
            throw new IcapConnectionException('Pool is closed.');
        }

        $key = $this->key($config);
        $now = ($this->clock)();

        while (!empty($this->idle[$key])) {
            $entry = array_pop($this->idle[$key]);
            $socket = $entry['socket'];

            if ($socket->isClosed()) {
                continue;
            }

            if (($now - $entry['idleSince']) > $this->maxIdleSeconds) {
                $socket->close();
                continue;
            }

            return $socket;
        }

        return ($this->connector)($config, $cancellation);
    }

    #[\Override]
    public function release(Config $config, SocketInterface $socket): void
    {
        if ($socket->isClosed() || $this->closed) {
            $socket->close();
            return;
        }

        $key = $this->key($config);
        $idleCount = count($this->idle[$key] ?? []);
        $effectiveCap = $this->effectiveMaxConnections();

        if ($idleCount >= $effectiveCap) {
            $socket->close();
            return;
        }

        $this->idle[$key][] = [
            'socket' => $socket,
            'idleSince' => ($this->clock)(),
        ];
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->idle as $entries) {
            foreach ($entries as $entry) {
                $entry['socket']->close();
            }
        }
        $this->idle = [];
    }

    /**
     * Extract the `Max-Connections` header from an OPTIONS response and
     * apply it as the server-side idle cap. Subsequent {@see release()}
     * calls use `min(localCap, serverMaxConnections)`.
     *
     * Typical usage after an OPTIONS round-trip:
     *
     *     $response = $client->options('/avscan')->await();
     *     $pool->tuneFromOptions($response);
     *
     * @see IcapResponse
     *
     * @throws IcapMalformedResponseException When the response carries
     *         conflicting `Max-Connections` fields
     */
    public function tuneFromOptions(IcapResponse $response): void
    {
        $maxConn = (int) (HeaderFields::single($response->headers, 'Max-Connections') ?? '0');
        if ($maxConn >= 1) {
            $this->serverMaxConnections = $maxConn;
        }
    }

    /**
     * Effective idle cap: min(localCap, serverMaxConnections) when the
     * server advertised a Max-Connections header, otherwise localCap.
     */
    private function effectiveMaxConnections(): int
    {
        if ($this->serverMaxConnections !== null) {
            return min($this->maxConnectionsPerHost, $this->serverMaxConnections);
        }

        return $this->maxConnectionsPerHost;
    }

    private function key(Config $config): string
    {
        $key = $config->host . ':' . $config->port;
        $tls = $config->getTlsContext();
        if ($tls !== null) {
            $key .= ':tls:' . $this->tlsToken($tls);
        }

        return $key;
    }

    /**
     * The pool's own identity for a TLS context: one number per context
     * object, handed out once and never again. An object hash would not do:
     * it is recycled as soon as the context is collected, and connectTls()
     * connects with a clone of a context without peer name, so nothing keeps
     * the original alive while its idle socket is. The next context to take
     * over the hash would then be served the old socket (B07).
     *
     * The counter only grows. Past PHP_INT_MAX the increment throws a
     * TypeError instead of wrapping, so an identity is never issued twice.
     */
    private function tlsToken(ClientTlsContext $tls): int
    {
        return $this->tlsTokens[$tls] ??= ++$this->lastTlsToken;
    }

    /**
     * @return Closure(Config, ?Cancellation): SocketInterface
     */
    private static function defaultConnector(): Closure
    {
        return static function (Config $config, ?Cancellation $cancellation): SocketInterface {
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
                    sprintf('Pooled connect to %s:%d failed.', $config->host, $config->port),
                    0,
                    $e,
                );
            }
        };
    }
}

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
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket as SocketInterface;
use Closure;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;

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
 * The pool keeps no separate idle timer — c-icap and most ICAP
 * vendors send `Connection: close` once they're done with a session
 * (or simply close the socket); the next {@see acquire()} drops the
 * stale entry and reconnects. A future enhancement could add an
 * idle-time eviction sweep if real deployments show socket
 * accumulation.
 */
final class AmpConnectionPool implements ConnectionPoolInterface
{
    /** @var array<string, list<SocketInterface>> */
    private array $idle = [];

    private bool $closed = false;

    /** @var Closure(Config, ?Cancellation): SocketInterface */
    private Closure $connector;

    /**
     * @param int                                                                  $maxConnectionsPerHost cap on idle sockets per host:port[:tls] key
     * @param (Closure(Config, ?Cancellation): SocketInterface)|null               $connector              optional override — production code uses the amphp connector; tests can inject pre-built socket pairs
     */
    public function __construct(
        private int $maxConnectionsPerHost = 8,
        ?Closure $connector = null,
    ) {
        if ($maxConnectionsPerHost < 1) {
            throw new \InvalidArgumentException(
                'maxConnectionsPerHost must be >= 1, got: ' . $maxConnectionsPerHost,
            );
        }

        $this->connector = $connector ?? self::defaultConnector();
    }

    #[\Override]
    public function acquire(Config $config, ?Cancellation $cancellation = null): SocketInterface
    {
        if ($this->closed) {
            throw new IcapConnectionException('Pool is closed.');
        }

        $key = $this->key($config);

        while (!empty($this->idle[$key])) {
            $socket = array_pop($this->idle[$key]);
            if (!$socket->isClosed()) {
                return $socket;
            }
            // Drop the stale entry and try the next one.
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

        if ($idleCount >= $this->maxConnectionsPerHost) {
            // Cap reached — closing the surplus socket is the right
            // thing per RFC 3507 §4.10.2 (servers also bound this via
            // Max-Connections; a future enhancement could read that
            // value back and tune the cap automatically).
            $socket->close();
            return;
        }

        $this->idle[$key][] = $socket;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->idle as $sockets) {
            foreach ($sockets as $socket) {
                $socket->close();
            }
        }
        $this->idle = [];
    }

    private function key(Config $config): string
    {
        return $config->host . ':' . $config->port . ($config->getTlsContext() !== null ? ':tls' : '');
    }

    /**
     * @return Closure(Config, ?Cancellation): SocketInterface
     */
    private static function defaultConnector(): Closure
    {
        return static function (Config $config, ?Cancellation $cancellation): SocketInterface {
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
                    sprintf('Pooled connect to %s:%d failed.', $config->host, $config->port),
                    0,
                    $e,
                );
            }
        };
    }
}

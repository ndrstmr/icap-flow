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
 * No-op connection pool: every {@see acquire()} opens a fresh TCP/TLS
 * connection, every {@see release()} closes it immediately. No idle
 * sockets are ever kept.
 *
 * Use this when connection reuse is explicitly unwanted (e.g. testing,
 * debugging, or ICAP servers that close the connection after every
 * request anyway).
 *
 * @see AmpConnectionPool for the keep-alive variant
 */
final class NullConnectionPool implements ConnectionPoolInterface
{
    /** @var Closure(Config, ?Cancellation): SocketInterface */
    private Closure $connector;

    /**
     * @param (Closure(Config, ?Cancellation): SocketInterface)|null $connector optional override for testing
     */
    public function __construct(?Closure $connector = null)
    {
        $this->connector = $connector ?? self::defaultConnector();
    }

    #[\Override]
    public function acquire(Config $config, ?Cancellation $cancellation = null): SocketInterface
    {
        return ($this->connector)($config, $cancellation);
    }

    #[\Override]
    public function release(Config $config, SocketInterface $socket): void
    {
        $socket->close();
    }

    #[\Override]
    public function close(): void
    {
        // Nothing to do — no idle sockets are ever stored.
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
                    sprintf('NullConnectionPool connect to %s:%d failed.', $config->host, $config->port),
                    0,
                    $e,
                );
            }
        };
    }
}

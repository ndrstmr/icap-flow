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
use Amp\Socket;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapUri;
use Revolt\EventLoop;

/**
 * Opens the connection to an ICAP server in the two phases it has, each with a limit of its own (M10): the TCP
 * connection within `socketTimeout` (the connector's own timer, which also ends with the cancellation it is given),
 * and, with a TLS context, the handshake within `tlsHandshakeTimeout`. The handshake is a phase of its own because
 * it can hang on a server that accepts the connection and says nothing, and because its limit is not the one of
 * the connection: `Socket\connectTls()` has no limit for it but the cancellation of the whole call.
 *
 * It does what `connectTls()` does: the peer name of the TLS context is the host when it has none, and the socket is
 * made by the connector with that context. The cancellation it is given is the caller's, the budget's or the window of
 * the connection setup; it ends both phases and stays what it is, `Amp\CancelledException`. Only the limit of the
 * handshake is an {@see IcapTimeoutException}.
 *
 * One place for the three ways to open a connection (transport, pool, null pool).
 *
 * @internal
 */
final class SocketOpener
{
    /**
     * @throws Socket\ConnectException When the TCP connection cannot be made
     * @throws Socket\TlsException     When the handshake fails
     * @throws IcapTimeoutException    When the handshake does not finish within `tlsHandshakeTimeout`
     * @throws CancelledException      When the cancellation ends the connection setup
     */
    public static function open(Config $config, ?Cancellation $cancellation): SocketInterface
    {
        $tls = $config->getTlsContext();
        $url = IcapUri::tcp($config->host, $config->port);
        $context = (new ConnectContext())->withConnectTimeout($config->getSocketTimeout());

        if ($tls === null) {
            return Socket\connect($url, $context, $cancellation);
        }

        if ($tls->getPeerName() === '') {
            $host = parse_url($url, PHP_URL_HOST);
            $tls = $tls->withPeerName(is_string($host) ? $host : '');
        }

        $socket = Socket\connect($url, $context->withTlsContext($tls), $cancellation);
        self::handshake($socket, $config->getTlsHandshakeTimeout(), $cancellation);

        return $socket;
    }

    /**
     * The window of the handshake: its own limit, and the cancellation of the connection setup. The timer and the
     * subscription are removed on every way out, not left to destructors. A socket whose handshake failed is closed.
     */
    private static function handshake(SocketInterface $socket, float $seconds, ?Cancellation $cancellation): void
    {
        $window = new DeferredCancellation();
        $expired = false;
        $timer = EventLoop::delay($seconds, static function () use (&$expired, $window): void {
            $expired = true;
            $window->cancel();
        });
        $watch = $cancellation?->subscribe(static function () use ($window): void {
            $window->cancel();
        });

        try {
            $socket->setupTls($window->getCancellation());
        } catch (\Throwable $e) {
            $socket->close();

            if ($e instanceof CancelledException) {
                // The caller's cancellation, or the budget, stays what it is; only the limit of the handshake is ours.
                $cancellation?->throwIfRequested();
                if ($expired) {
                    throw new IcapTimeoutException(
                        sprintf('The TLS handshake did not finish within %s seconds.', $seconds),
                        0,
                        $e,
                    );
                }
            }

            throw $e;
        } finally {
            EventLoop::cancel($timer);
            if ($cancellation !== null && $watch !== null) {
                $cancellation->unsubscribe($watch);
            }
        }
    }
}

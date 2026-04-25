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
 * Response framing is done by {@see ResponseFrameReader} so the
 * read loop terminates as soon as the message is complete — no
 * dependency on the server closing the socket. That means the
 * `Connection: close` request-side hack from M4 is gone, and a
 * future pooling implementation can reuse the socket for the next
 * request without changing this code.
 */
final class AsyncAmpTransport implements TransportInterface
{
    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): \Amp\Future
    {
        /** @var \Amp\Future<string> $future */
        $future = async(function () use ($config, $rawRequest, $cancellation): string {
            $socket = null;
            $tls = $config->getTlsContext();
            $connectionUrl = sprintf('tcp://%s:%d', $config->host, $config->port);
            $connectContext = (new ConnectContext())
                ->withConnectTimeout($config->getSocketTimeout());
            if ($tls !== null) {
                $connectContext = $connectContext->withTlsContext($tls);
            }
            $timeoutCancellation = new TimeoutCancellation($config->getStreamTimeout());
            $cancellation = $cancellation === null
                ? $timeoutCancellation
                : new CompositeCancellation($cancellation, $timeoutCancellation);

            try {
                if ($tls !== null) {
                    $socket = Socket\connectTls($connectionUrl, $connectContext, $cancellation);
                } else {
                    $socket = Socket\connect($connectionUrl, $connectContext, $cancellation);
                }
                foreach ($rawRequest as $chunk) {
                    if ($chunk !== '') {
                        $socket->write($chunk);
                    }
                }

                $reader = new ResponseFrameReader(
                    maxResponseSize: $config->getMaxResponseSize(),
                    maxHeaderLineLength: $config->getMaxHeaderLineLength(),
                );
                return $reader->readFrom(static fn (): ?string => $socket->read($cancellation));
            } catch (Socket\ConnectException $e) {
                throw new IcapConnectionException(
                    sprintf('Async connection to %s:%d failed.', $config->host, $config->port),
                    0,
                    $e,
                );
            } finally {
                $socket?->close();
            }
        });

        return $future;
    }
}

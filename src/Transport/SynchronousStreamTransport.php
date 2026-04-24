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
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;

/**
 * Blocking transport using plain PHP stream sockets.
 *
 * TLS is explicitly NOT supported here — the synchronous transport is
 * intended for quick CLI / test usage; production deployments should
 * use {@see AsyncAmpTransport} (TLS, streaming, cancellations).
 *
 * Hardened per finding I of the consolidated review:
 *   - connect + read/write timeouts come from Config, not a hard 5 s;
 *   - every branch closes the socket via try/finally;
 *   - the read loop enforces Config::maxResponseSize to defend
 *     against a hostile server sending unbounded bytes.
 */
final class SynchronousStreamTransport implements TransportInterface
{
    private const int READ_CHUNK_SIZE = 8192;

    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): \Amp\Future
    {
        if ($config->getTlsContext() !== null) {
            throw new IcapConnectionException(
                'SynchronousStreamTransport does not support TLS; use AsyncAmpTransport for icaps://.',
            );
        }

        // Cancellation is honoured opportunistically: we check it
        // between read/write iterations. The blocking PHP stream API
        // doesn't allow interrupting an in-flight syscall, so a hung
        // server is still bounded only by stream_set_timeout(). The
        // async transport gives true cancellation semantics.
        $cancellation?->throwIfRequested();

        $errno = 0;
        $errstr = '';
        $address = sprintf('tcp://%s:%d', $config->host, $config->port);
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $config->getSocketTimeout(),
            STREAM_CLIENT_CONNECT,
        );
        if ($stream === false) {
            throw new IcapConnectionException(
                sprintf('Connection to %s failed: %s', $address, $errstr !== '' ? $errstr : 'unknown error'),
            );
        }

        try {
            stream_set_timeout($stream, (int) $config->getStreamTimeout());

            foreach ($rawRequest as $chunk) {
                $cancellation?->throwIfRequested();
                if ($chunk !== '') {
                    fwrite($stream, $chunk);
                }
            }

            $maxBytes = $config->getMaxResponseSize();
            $response = '';
            $received = 0;
            while (!feof($stream)) {
                $cancellation?->throwIfRequested();
                $read = fread($stream, self::READ_CHUNK_SIZE);
                if ($read === false || $read === '') {
                    break;
                }
                $received += strlen($read);
                if ($received > $maxBytes) {
                    throw new IcapMalformedResponseException(
                        sprintf('ICAP response exceeded max size (%d bytes).', $maxBytes),
                    );
                }
                $response .= $read;
            }

            return \Amp\Future::complete($response);
        } finally {
            fclose($stream);
        }
    }
}

<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

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
    public function request(Config $config, iterable $rawRequest): \Amp\Future
    {
        if ($config->getTlsContext() !== null) {
            throw new IcapConnectionException(
                'SynchronousStreamTransport does not support TLS; use AsyncAmpTransport for icaps://.',
            );
        }

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
                if ($chunk !== '') {
                    fwrite($stream, $chunk);
                }
            }

            $maxBytes = $config->getMaxResponseSize();
            $response = '';
            $received = 0;
            while (!feof($stream)) {
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

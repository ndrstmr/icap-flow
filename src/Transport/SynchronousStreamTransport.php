<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;

/**
 * Simple blocking transport using PHP stream sockets.
 *
 * M2 will replace the hardcoded 5-second connect timeout with the
 * Config-supplied one, add a response-size limit, and wrap the socket in
 * a try/finally close. The current implementation is carried over from
 * v1.0 intentionally so M1 can focus on the wire-format changes alone.
 */
final class SynchronousStreamTransport implements TransportInterface
{
    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest): \Amp\Future
    {
        $errno = 0;
        $errstr = '';
        $address = sprintf('tcp://%s:%d', $config->host, $config->port);
        $stream = @stream_socket_client($address, $errno, $errstr, 5);
        if ($stream === false) {
            throw new IcapConnectionException($errstr ?: 'Connection failed');
        }

        foreach ($rawRequest as $chunk) {
            if ($chunk !== '') {
                fwrite($stream, $chunk);
            }
        }
        $response = stream_get_contents($stream);
        fclose($stream);

        return \Amp\Future::complete($response !== false ? $response : '');
    }
}

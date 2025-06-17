<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;

class SynchronousStreamTransport implements TransportInterface
{
    public function request(Config $config, string $rawRequest): string
    {
        $errno = 0;
        $errstr = '';
        $address = sprintf('tcp://%s:%d', $config->host, $config->port);
        $stream = @stream_socket_client($address, $errno, $errstr, 5);
        if ($stream === false) {
            throw new IcapConnectionException($errstr ?: 'Connection failed');
        }

        fwrite($stream, $rawRequest);
        $response = stream_get_contents($stream);
        fclose($stream);

        return $response !== false ? $response : '';
    }
}

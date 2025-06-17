<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Amp\Socket; // for connect etc
use function Amp\async;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;

final class AsyncAmpTransport implements TransportInterface
{
    public function request(Config $config, string $rawRequest): string
    {
        return async(function () use ($config, $rawRequest) {
            $socket = null;
            $connectionUrl = sprintf('tcp://%s:%d', $config->host, $config->port);
            try {
                $socket = Socket\connect($connectionUrl);
                $socket->write($rawRequest);

                $response = '';
                while (null !== ($chunk = $socket->read())) {
                    $response .= $chunk;
                }

                return $response;
            } catch (Socket\ConnectException $e) {
                throw new IcapConnectionException(
                    sprintf('Async connection to %s:%d failed.', $config->host, $config->port),
                    0,
                    $e
                );
            } finally {
                if ($socket) {
                    $socket->close();
                }
            }
        })->await();
    }
}

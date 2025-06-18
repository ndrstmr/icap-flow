<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Amp\Socket;
use Amp\Socket\ConnectContext;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;

use function Amp\async;

/**
 * Asynchronous transport implementation using amphp/socket.
 */
final class AsyncAmpTransport implements TransportInterface
{
    /**
     * @return \Amp\Future<string>
     */
    public function request(Config $config, string $rawRequest): \Amp\Future
    {
        return async(function () use ($config, $rawRequest) {
            $socket = null;
            $connectionUrl = sprintf('tcp://%s:%d', $config->host, $config->port);
            $connectContext = (new ConnectContext())
                ->withConnectTimeout($config->getSocketTimeout());
            $cancellation = new TimeoutCancellation($config->getStreamTimeout());

            try {
                $socket = Socket\connect($connectionUrl, $connectContext, $cancellation);
                $socket->write($rawRequest);

                $response = '';
                while (null !== ($chunk = $socket->read($cancellation))) {
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
        });
    }
}

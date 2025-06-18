<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

/**
 * Transport and timeout configuration for the ICAP clients.
 */
final readonly class Config
{
    /**
     * @param string $host           Hostname or IP of the ICAP server
     * @param int    $port           TCP port, defaults to 1344
     * @param float  $socketTimeout  Timeout in seconds for establishing the connection
     * @param float  $streamTimeout  Timeout in seconds for reading/writing
     */
    public function __construct(
        public string $host,
        public int $port = 1344,
        private float $socketTimeout = 10.0,
        private float $streamTimeout = 10.0,
    ) {
    }

    /**
     * Socket timeout in seconds.
     */
    public function getSocketTimeout(): float
    {
        return $this->socketTimeout;
    }

    /**
     * Stream timeout in seconds.
     */
    public function getStreamTimeout(): float
    {
        return $this->streamTimeout;
    }
}

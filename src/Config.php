<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

final readonly class Config
{
    public function __construct(
        public string $host,
        public int $port = 1344,
        private float $socketTimeout = 10.0,
        private float $streamTimeout = 10.0,
    ) {
    }

    public function getSocketTimeout(): float
    {
        return $this->socketTimeout;
    }

    public function getStreamTimeout(): float
    {
        return $this->streamTimeout;
    }
}

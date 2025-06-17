<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

final readonly class Config
{
    public function __construct(
        public string $host,
        public int $port = 1344,
    ) {
    }
}

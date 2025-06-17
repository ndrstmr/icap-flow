<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;

interface TransportInterface
{
    /**
     * @return \Amp\Future<string>
     */
    public function request(Config $config, string $rawRequest): \Amp\Future;
}

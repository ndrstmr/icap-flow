<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;

/**
 * Contract for ICAP transport implementations.
 */
interface TransportInterface
{
    /**
     * @param Config $config
     * @param string $rawRequest
     *
     * @return \Amp\Future<string>
     */
    public function request(Config $config, string $rawRequest): \Amp\Future;
}

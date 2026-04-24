<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;

/**
 * Contract for ICAP transport implementations.
 *
 * The raw request is supplied as an iterable of byte chunks so large
 * encapsulated HTTP bodies are streamed onto the socket without being
 * concatenated in memory.
 */
interface TransportInterface
{
    /**
     * @param iterable<string> $rawRequest Request bytes, in transport order
     *
     * @return \Amp\Future<string>
     */
    public function request(Config $config, iterable $rawRequest): \Amp\Future;
}

<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;

/**
 * Formats ICAP requests into a sequence of raw byte chunks ready for
 * transport.
 */
interface RequestFormatterInterface
{
    /**
     * Render the ICAP request into a sequence of raw byte chunks.
     *
     * Returning an iterable (instead of one large string) allows transports
     * to stream large encapsulated HTTP bodies to the socket without
     * buffering the whole request in memory.
     *
     * @return iterable<string>
     */
    public function format(IcapRequest $request): iterable;
}

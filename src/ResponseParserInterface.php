<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * Parses raw strings into {@link IcapResponse} objects.
 */
interface ResponseParserInterface
{
    /**
     * @param string $rawResponse
     */
    public function parse(string $rawResponse): IcapResponse;
}

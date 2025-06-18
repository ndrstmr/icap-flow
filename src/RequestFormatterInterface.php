<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;

/**
 * Formats ICAP requests into raw strings.
 */
interface RequestFormatterInterface
{
    /**
     * @param IcapRequest $request
     */
    public function format(IcapRequest $request): string;
}

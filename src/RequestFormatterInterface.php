<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;

interface RequestFormatterInterface
{
    public function format(IcapRequest $request): string;
}

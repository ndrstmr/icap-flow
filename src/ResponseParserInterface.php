<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;

interface ResponseParserInterface
{
    public function parse(string $rawResponse): IcapResponse;
}

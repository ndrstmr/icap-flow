<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;

interface PreviewStrategyInterface
{
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision;
}

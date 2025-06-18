<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * Strategy interface for handling preview responses.
 */
interface PreviewStrategyInterface
{
    /**
     * @param IcapResponse $previewResponse
     */
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision;
}

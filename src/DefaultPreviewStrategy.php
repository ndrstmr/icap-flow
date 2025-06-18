<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapResponseException;

/**
 * Default strategy for interpreting preview responses.
 */
class DefaultPreviewStrategy implements PreviewStrategyInterface
{
    /**
     * Decide whether to continue sending the body after a preview response.
     */
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            204 => PreviewDecision::ABORT_CLEAN,
            100 => PreviewDecision::CONTINUE_SENDING,
            default => throw new IcapResponseException('Unexpected preview status code: ' . $previewResponse->statusCode),
        };
    }
}

<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapResponseException;

class DefaultPreviewStrategy implements PreviewStrategyInterface
{
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            204 => PreviewDecision::ABORT_CLEAN,
            100 => PreviewDecision::CONTINUE_SENDING,
            default => throw new IcapResponseException('Unexpected preview status code: ' . $previewResponse->statusCode),
        };
    }
}

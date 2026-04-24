<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * You may obtain a copy of the Licence at:
 *
 *     https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 */

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapResponseException;

/**
 * Default strategy for interpreting preview responses.
 */
final class DefaultPreviewStrategy implements PreviewStrategyInterface
{
    /**
     * Decide whether to continue sending the body after a preview response.
     */
    #[\Override]
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            204 => PreviewDecision::ABORT_CLEAN,
            100 => PreviewDecision::CONTINUE_SENDING,
            default => throw new IcapResponseException('Unexpected preview status code: ' . $previewResponse->statusCode),
        };
    }
}

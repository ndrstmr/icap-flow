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

/**
 * Decide how to handle a partial-preview response. The client rejects error and unexpected statuses before calling the
 * strategy. For 100 the strategy must return CONTINUE_SENDING; for final 200, 204 or 206 it must return an abort
 * decision. An inconsistent decision raises IcapProtocolException.
 */
interface PreviewStrategyInterface
{
    /**
     * @param IcapResponse $previewResponse
     */
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision;
}

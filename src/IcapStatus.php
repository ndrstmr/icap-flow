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

use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapServerException;

/**
 * What an ICAP status says about a failure, in one place (N03): the scan path ({@see IcapClient}) and the preview
 * strategy ({@see DefaultPreviewStrategy}) classify 4xx and 5xx alike, so a 503 is an {@see IcapServerException}
 * whether it answers a preview, a continuation or a plain request.
 *
 * @internal
 */
final class IcapStatus
{
    /**
     * The exception for a 4xx (the request was refused) or a 5xx (the server failed), or null for any other status.
     * The message carries the code and nothing the server sent.
     */
    public static function failure(int $code): IcapClientException|IcapServerException|null
    {
        if ($code >= 400 && $code < 500) {
            return new IcapClientException(
                sprintf('ICAP client error (%d) — request rejected by server.', $code),
                $code,
            );
        }

        if ($code >= 500 && $code < 600) {
            return new IcapServerException(
                sprintf('ICAP server error (%d) — server failed to service the request.', $code),
                $code,
            );
        }

        return null;
    }
}

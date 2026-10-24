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
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;

/**
 * Default strategy for interpreting preview responses.
 *
 * Handles RFC 3507 §4.3.3 / §6: servers may respond 200 or 206 during a
 * preview exchange when malware is detected in the first chunk — without
 * waiting for the full body.  Earlier versions of this class threw on
 * 200/206, making the ABORT_INFECTED branch in {@see IcapClient}
 * unreachable.  Fixed in v2.1.1 (finding B, 3/4 reviewers).
 */
final class DefaultPreviewStrategy implements PreviewStrategyInterface
{
    /** @var list<string> */
    private array $virusFoundHeaders;

    /**
     * @param list<string> $virusFoundHeaders Ordered list of vendor virus-name
     *   headers to inspect when a 200/206 preview response arrives.  The first
     *   header present in the response wins.  Defaults to ['X-Virus-Name'] for
     *   backward compatibility; production deployments should pass
     *   Config::getVirusFoundHeaders().
     */
    public function __construct(array $virusFoundHeaders = ['X-Virus-Name'])
    {
        $this->virusFoundHeaders = $virusFoundHeaders;
    }

    /**
     * Decide whether to continue sending the body after a preview response.
     *
     * Status semantics:
     * - 100 Continue   → server wants the rest of the body.
     * - 204 No Content → server is done; file is clean.
     * - 200 / 206      → server finished early; inspect virus headers to
     *                    determine whether the file is infected or clean.
     * - 4xx            → {@see IcapClientException}, 5xx → {@see IcapServerException}:
     *                    the same types as for the answer to any other request (N03).
     * - anything else  → protocol error.
     *
     * @throws IcapClientException   When the status is in the 4xx range
     * @throws IcapServerException   When the status is in the 5xx range
     * @throws IcapProtocolException When the status is none of the above
     */
    #[\Override]
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        $code = $previewResponse->statusCode;

        return match (true) {
            $code === 100 => PreviewDecision::CONTINUE_SENDING,
            $code === 204 => PreviewDecision::ABORT_CLEAN,
            $code === 200,
            $code === 206 => $this->classifyBodyResponse($previewResponse),
            default => throw IcapStatus::failure($code) ?? new IcapProtocolException(
                'Unexpected preview status code: ' . $code,
                $code,
            ),
        };
    }

    /**
     * Inspect the 200/206 response for vendor virus headers.
     *
     * Returns ABORT_INFECTED if any configured virus header is present, in
     * any spelling, ABORT_CLEAN otherwise. Uses the same lookup as the scan
     * verdict in {@see IcapClient}, so both paths agree.
     */
    private function classifyBodyResponse(IcapResponse $response): PreviewDecision
    {
        return HeaderFields::first($response->headers, $this->virusFoundHeaders) !== null
            ? PreviewDecision::ABORT_INFECTED
            : PreviewDecision::ABORT_CLEAN;
    }
}

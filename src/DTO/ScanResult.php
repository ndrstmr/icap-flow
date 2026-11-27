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

namespace Ndrstmr\Icap\DTO;

/**
 * Result object returned after scanning content via ICAP.
 */
final readonly class ScanResult
{
    public function __construct(
        private bool $isInfected,
        private ?string $virusName,
        private IcapResponse $originalResponse,
        private ?string $blockReason = null,
    ) {
    }

    /**
     * Whether the scanned content was rejected as infected or blocked by policy.
     *
     * For a policy block without a virus header ({@see \Ndrstmr\Icap\VirusHeaderVerdict} with configured block
     * headers), this method returns `true` so callers that only check `isInfected()` stay fail-closed;
     * {@see getVirusName()} is then `null` and {@see getBlockReason()} returns the server's reason.
     */
    public function isInfected(): bool
    {
        return $this->isInfected;
    }

    /**
     * Name of the detected virus, if any.
     *
     * Returns `null` for a clean scan and for a policy block without a virus header (see {@see getBlockReason()}).
     */
    public function getVirusName(): ?string
    {
        return $this->virusName;
    }

    /**
     * Policy block reason reported by a configured block header when no virus header was present, or `null`.
     *
     * This text comes from the ICAP server and is untrusted input; icap-flow never logs it.
     */
    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }

    /**
     * Access to the original ICAP response for advanced use.
     */
    public function getOriginalResponse(): IcapResponse
    {
        return $this->originalResponse;
    }
}

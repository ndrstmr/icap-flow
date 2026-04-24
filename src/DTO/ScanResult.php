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
    ) {
    }

    /**
     * Whether the scanned content was infected.
     */
    public function isInfected(): bool
    {
        return $this->isInfected;
    }

    /**
     * Name of the detected virus, if any.
     */
    public function getVirusName(): ?string
    {
        return $this->virusName;
    }

    /**
     * Access to the original ICAP response for advanced use.
     */
    public function getOriginalResponse(): IcapResponse
    {
        return $this->originalResponse;
    }
}

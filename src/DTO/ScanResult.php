<?php

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

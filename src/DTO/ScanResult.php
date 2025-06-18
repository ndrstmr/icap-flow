<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

final readonly class ScanResult
{
    public function __construct(
        private bool $isInfected,
        private ?string $virusName,
        private IcapResponse $originalResponse,
    ) {
    }

    public function isInfected(): bool
    {
        return $this->isInfected;
    }

    public function getVirusName(): ?string
    {
        return $this->virusName;
    }

    public function getOriginalResponse(): IcapResponse
    {
        return $this->originalResponse;
    }
}

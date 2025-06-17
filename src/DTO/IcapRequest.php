<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

final readonly class IcapRequest
{
    /** @var array<string, string[]> */
    public array $headers;

    public function __construct(
        public string $method,
        array $headers = [],
        public string $body = ''
    ) {
        $this->headers = array_map(fn($v) => (array)$v, $headers);
    }

    /**
     * @param string|string[] $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        $headers = $this->headers;
        $headers[$name] = (array) $value;

        return new self(
            $this->method,
            $headers,
            $this->body,
        );
    }
}

<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

final readonly class IcapResponse
{
    /** @var array<string, string[]> */
    public array $headers;

    /**
     * @param int $statusCode HTTP status code, e.g. 200, 204, 500
     * @param array<string, string[]> $headers Response headers
     * @param string $body Response body content
     */
    public function __construct(
        public int $statusCode,
        array $headers = [],
        public string $body = ''
    ) {
        $this->headers = array_map(fn ($v) => (array) $v, $headers);
    }

    /**
     * @param string|string[] $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        $headers = $this->headers;
        $headers[$name] = (array) $value;

        return new self(
            $this->statusCode,
            $headers,
            $this->body,
        );
    }
}

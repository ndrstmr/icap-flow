<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

final readonly class IcapRequest
{
    /** @var array<string, string[]> */
    public array $headers;

    /**
     * @param string $method HTTP method, e.g. 'GET', 'POST', 'OPTIONS'
     * @param string $uri Request URI, e.g. 'icap://example.com/service'
     * @param array<string, string[]> $headers Request headers
     * @param mixed $body Request body content, can be a string or stream resource
     */
    public function __construct(
        public string $method,
        public string $uri = '/',
        array $headers = [],
        public mixed $body = ''
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
            $this->method,
            $this->uri,
            $headers,
            $this->body,
        );
    }
}

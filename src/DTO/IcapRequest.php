<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

/**
 * Immutable data object representing an ICAP request.
 *
 * An ICAP request is an ICAP envelope (method/URI/ICAP-headers) that
 * optionally carries a single encapsulated HTTP request (REQMOD) or a
 * single encapsulated HTTP response (RESPMOD), per RFC 3507 §4.4.
 *
 * Rendering into wire bytes is the {@see \Ndrstmr\Icap\RequestFormatter}'s
 * job; this class is a pure value object.
 */
final readonly class IcapRequest
{
    /** @var array<string, string[]> */
    public array $headers;

    /**
     * @param string                  $method               ICAP method (OPTIONS, REQMOD, RESPMOD)
     * @param string                  $uri                  Fully-qualified ICAP URI, e.g. icap://host:1344/service
     * @param array<string, string[]> $headers              ICAP headers (Host is filled in from $uri when missing)
     * @param HttpRequest|null        $encapsulatedRequest  Encapsulated HTTP request (REQMOD)
     * @param HttpResponse|null       $encapsulatedResponse Encapsulated HTTP response (RESPMOD)
     * @param bool                    $previewIsComplete    When true, the body attached to the encapsulated HTTP
     *                                                      message represents the complete payload in a preview
     *                                                      scenario, so the terminator must be `0; ieof\r\n\r\n`
     *                                                      rather than `0\r\n\r\n` (RFC 3507 §4.5).
     */
    public function __construct(
        public string $method,
        public string $uri = '/',
        array $headers = [],
        public ?HttpRequest $encapsulatedRequest = null,
        public ?HttpResponse $encapsulatedResponse = null,
        public bool $previewIsComplete = false,
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
            $this->encapsulatedRequest,
            $this->encapsulatedResponse,
            $this->previewIsComplete,
        );
    }
}

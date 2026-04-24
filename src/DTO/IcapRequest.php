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
     * @param array<string, string|string[]> $headers       ICAP headers (Host is filled in from $uri when missing);
     *                                                       scalar values are promoted to a one-element list
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

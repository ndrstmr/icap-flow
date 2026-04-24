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
 * Encapsulated HTTP request carried inside an ICAP REQMOD request
 * (RFC 3507 §4.8). The {@see \Ndrstmr\Icap\RequestFormatter} renders its
 * request-line + headers into the `req-hdr` section and streams the body
 * into the `req-body` section.
 */
final readonly class HttpRequest
{
    /**
     * @param string                  $method        HTTP method, e.g. "POST"
     * @param string                  $requestTarget Request-target as per RFC 7230 §5.3 (path + optional query)
     * @param array<string, string[]> $headers       HTTP header list
     * @param resource|string|null    $body          HTTP body bytes, or a readable stream resource, or null for header-only
     * @param string                  $httpVersion   HTTP version label, default HTTP/1.1
     */
    public function __construct(
        public string $method,
        public string $requestTarget = '/',
        public array $headers = [],
        public mixed $body = null,
        public string $httpVersion = 'HTTP/1.1',
    ) {
    }
}

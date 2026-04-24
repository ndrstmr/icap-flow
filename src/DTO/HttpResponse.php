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
 * Encapsulated HTTP response carried inside an ICAP RESPMOD request
 * (RFC 3507 §4.9) or returned in a 200 response with modifications.
 *
 * Body is either a string (treated as raw bytes) or a stream resource the
 * {@see \Ndrstmr\Icap\RequestFormatter} reads chunk-by-chunk to avoid
 * buffering the whole payload in memory.
 */
final readonly class HttpResponse
{
    /**
     * @param int                     $statusCode  HTTP status code, e.g. 200
     * @param string                  $reasonPhrase Reason phrase, e.g. "OK"
     * @param array<string, string[]> $headers      HTTP header list
     * @param resource|string|null    $body         HTTP body bytes, or a readable stream resource, or null for header-only
     * @param string                  $httpVersion  HTTP version label, default HTTP/1.1
     */
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase = 'OK',
        public array $headers = [],
        public mixed $body = null,
        public string $httpVersion = 'HTTP/1.1',
    ) {
    }
}

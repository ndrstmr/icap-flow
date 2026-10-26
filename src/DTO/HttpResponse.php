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

use Ndrstmr\Icap\LocalFile;

/**
 * Encapsulated HTTP response carried inside an ICAP RESPMOD request
 * (RFC 3507 §4.9) or returned in a 200 response with modifications.
 *
 * Body is either a string (treated as raw bytes) or a stream resource the
 * {@see \Ndrstmr\Icap\RequestFormatter} reads chunk-by-chunk to avoid
 * buffering the whole payload in memory. A stream passed in stays the
 * caller's: it is read, never closed. When the headers announce a
 * `Content-Length`, the stream has to deliver exactly that many bytes, or
 * sending the request fails before the final chunk. A preview that carries
 * only the start of the body is the exception: the field describes all of it.
 */
final readonly class HttpResponse
{
    /**
     * @param int                     $statusCode  HTTP status code, e.g. 200
     * @param string                  $reasonPhrase Reason phrase, e.g. "OK"
     * @param array<string, string[]> $headers      HTTP header list
     * @param resource|string|LocalFile|null $body  HTTP body bytes, or a readable stream resource, or null for header-only;
     *        scanFile() and scanFileWithPreview() hand over the {@see LocalFile} they opened
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

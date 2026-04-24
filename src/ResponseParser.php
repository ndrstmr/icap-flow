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

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;

/**
 * Parses raw ICAP server responses into {@link IcapResponse} value
 * objects, honouring the `Encapsulated` header (RFC 3507 §4.4) to split
 * the encapsulated block into HTTP-header and HTTP-body sections before
 * decoding the HTTP chunked transfer encoding of the latter.
 */
final class ResponseParser implements ResponseParserInterface
{
    private const int DEFAULT_MAX_HEADER_COUNT = 100;
    private const int DEFAULT_MAX_HEADER_LINE = 8192;

    public function __construct(
        private readonly int $maxHeaderCount = self::DEFAULT_MAX_HEADER_COUNT,
        private readonly int $maxHeaderLineLength = self::DEFAULT_MAX_HEADER_LINE,
    ) {
        if ($maxHeaderCount < 1 || $maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('Parser limits must be >= 1');
        }
    }

    #[\Override]
    public function parse(string $rawResponse): IcapResponse
    {
        $separatorPos = $this->findHeaderBodySeparator($rawResponse);
        if ($separatorPos === null) {
            throw new IcapMalformedResponseException('Invalid ICAP response: missing header/body separator');
        }

        $head = substr($rawResponse, 0, $separatorPos);
        $encapsulatedBlock = substr($rawResponse, $separatorPos + 4);

        $lines = preg_split('/\r?\n/', $head);
        if ($lines === false || count($lines) === 0) {
            throw new IcapMalformedResponseException('Invalid ICAP response: no lines');
        }

        // +1 for the status line itself.
        if (count($lines) > $this->maxHeaderCount + 1) {
            throw new IcapMalformedResponseException(
                sprintf('ICAP response exceeded max header count (%d).', $this->maxHeaderCount),
            );
        }

        foreach ($lines as $line) {
            if (strlen($line) > $this->maxHeaderLineLength) {
                throw new IcapMalformedResponseException(
                    sprintf('ICAP response header exceeded max line length (%d).', $this->maxHeaderLineLength),
                );
            }
        }

        $statusLine = array_shift($lines);
        if (!preg_match('/^ICAP\/1\.\d\s+(\d+)(?:\s+.*)?$/', (string) $statusLine, $m)) {
            throw new IcapMalformedResponseException('Invalid status line: ' . (string) $statusLine);
        }
        $statusCode = (int) $m[1];

        $headers = $this->parseHeaderBlock($lines);

        $body = $this->extractDecodedBody($encapsulatedBlock, $headers);

        return new IcapResponse($statusCode, $headers, $body);
    }

    /**
     * Locate the `\r\n\r\n` (or `\n\n`) that separates the ICAP header
     * block from the encapsulated block. Returns the offset of the first
     * byte of the separator, or null if not found.
     */
    private function findHeaderBodySeparator(string $raw): ?int
    {
        $crlf = strpos($raw, "\r\n\r\n");
        if ($crlf !== false) {
            return $crlf;
        }
        $lf = strpos($raw, "\n\n");
        if ($lf !== false) {
            return $lf;
        }
        return null;
    }

    /**
     * Parse a flat list of header lines into a {@code name => list<value>}
     * map. Honours RFC 7230 §3.2.4 obsolete line folding: a line that
     * starts with HTAB or SP is treated as a continuation of the
     * previous header value (joined with a single space). c-icap uses
     * this folding form for the multi-line `X-Violations-Found` header
     * defined in RFC 3507 §6.4.
     *
     * @param  list<string>            $lines
     * @return array<string, string[]>
     */
    private function parseHeaderBlock(array $lines): array
    {
        /** @var array<string, string[]> $headers */
        $headers = [];
        $lastName = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Continuation line — append to the previous header's last
            // value with a single SP, the canonical "unfolded" form.
            if ($lastName !== null && ($line[0] === " " || $line[0] === "\t")) {
                $lastIdx = count($headers[$lastName]) - 1;
                $headers[$lastName][$lastIdx] .= ' ' . trim($line);
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new IcapMalformedResponseException('Malformed header line: ' . $line);
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $headers[$name][] = $value;
            $lastName = $name;
        }

        return $headers;
    }

    /**
     * Interpret the `Encapsulated` header and return the decoded HTTP
     * body as raw bytes. An empty string is returned when the response
     * carries no body (null-body, 204, OPTIONS, etc.).
     *
     * @param array<string, string[]> $icapHeaders
     */
    private function extractDecodedBody(string $encapsulatedBlock, array $icapHeaders): string
    {
        if ($encapsulatedBlock === '') {
            return '';
        }

        $encapsulatedHeader = $icapHeaders['Encapsulated'][0] ?? null;
        if ($encapsulatedHeader === null) {
            // No Encapsulated header → fall back to treating the trailing
            // bytes as a plain body (best-effort).
            return $encapsulatedBlock;
        }

        $entries = $this->parseEncapsulatedHeader($encapsulatedHeader);
        $bodyOffset = $entries['req-body'] ?? $entries['res-body'] ?? null;
        if ($bodyOffset === null) {
            // null-body or header-only: no encapsulated HTTP body to decode.
            return '';
        }

        if ($bodyOffset < 0 || $bodyOffset > strlen($encapsulatedBlock)) {
            throw new IcapMalformedResponseException(
                'Encapsulated body offset out of range: ' . $bodyOffset,
            );
        }

        $chunked = substr($encapsulatedBlock, $bodyOffset);
        return $this->decodeChunked($chunked);
    }

    /**
     * @return array<string, int>
     */
    private function parseEncapsulatedHeader(string $value): array
    {
        $entries = [];
        foreach (array_map('trim', explode(',', $value)) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $entries[trim($k)] = (int) trim($v);
        }
        return $entries;
    }

    /**
     * Decode an HTTP/1.1 chunked transfer-coded byte stream. Chunk
     * extensions (e.g. `0; ieof`) are tolerated but ignored — their
     * semantics belong on the request side (§4.5).
     */
    private function decodeChunked(string $chunked): string
    {
        $decoded = '';
        $i = 0;
        $len = strlen($chunked);

        while ($i < $len) {
            $eol = strpos($chunked, "\r\n", $i);
            if ($eol === false) {
                // Not chunked, or truncated — return what we have.
                break;
            }
            $sizeLine = substr($chunked, $i, $eol - $i);
            // Strip any chunk-extension (after ';').
            $sizeHex = explode(';', $sizeLine, 2)[0];
            $sizeHex = trim($sizeHex);
            if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                break;
            }
            $size = (int) hexdec($sizeHex);
            $i = $eol + 2;
            if ($size === 0) {
                break;
            }
            $decoded .= substr($chunked, $i, $size);
            $i += $size + 2; // skip CRLF after chunk
        }

        return $decoded;
    }
}

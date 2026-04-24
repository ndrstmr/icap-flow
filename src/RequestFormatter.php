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

use Ndrstmr\Icap\DTO\HttpRequest;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;

/**
 * RFC 3507–conformant formatter for ICAP requests.
 *
 * The formatter yields the request in three phases so large encapsulated
 * HTTP bodies never have to be buffered in memory:
 *
 *   1. ICAP request line + ICAP headers + blank line (with computed
 *      `Encapsulated` offsets).
 *   2. The encapsulated HTTP header block(s).
 *   3. The encapsulated HTTP body, transfer-encoded as HTTP/1.1 chunks,
 *      terminated by either `0\r\n\r\n` or `0; ieof\r\n\r\n` for a
 *      preview that already carries the complete payload (§4.5).
 *
 * @see https://www.rfc-editor.org/rfc/rfc3507#section-4
 */
final class RequestFormatter implements RequestFormatterInterface
{
    private const int CHUNK_SIZE = 8192;

    #[\Override]
    public function format(IcapRequest $request): iterable
    {
        // Build encapsulated HTTP header block(s) eagerly so we know the
        // `*-body` offset, which MUST appear in the Encapsulated header
        // before the body is written on the wire (RFC 3507 §4.4.1).
        $reqHeaderBlock = $request->encapsulatedRequest !== null
            ? $this->renderHttpRequestHeaders($request->encapsulatedRequest)
            : '';
        $resHeaderBlock = $request->encapsulatedResponse !== null
            ? $this->renderHttpResponseHeaders($request->encapsulatedResponse)
            : '';

        $bodySource = $this->resolveBodySource($request);
        $hasBody = $bodySource !== null;

        $encapsulated = $this->buildEncapsulatedHeader(
            hasReqHeaders: $reqHeaderBlock !== '',
            reqHeaderLength: strlen($reqHeaderBlock),
            hasResHeaders: $resHeaderBlock !== '',
            resHeaderLength: strlen($resHeaderBlock),
            bodyCarrier: $this->bodyCarrier($request),
            hasBody: $hasBody,
        );

        yield $this->renderIcapHead($request, $encapsulated);

        if ($reqHeaderBlock !== '') {
            yield $reqHeaderBlock;
        }
        if ($resHeaderBlock !== '') {
            yield $resHeaderBlock;
        }

        if ($hasBody) {
            yield from $this->chunkBody($bodySource, $request->previewIsComplete);
        }
    }

    /**
     * @return string|resource|null
     */
    private function resolveBodySource(IcapRequest $request): mixed
    {
        if ($request->encapsulatedResponse?->body !== null && $request->encapsulatedResponse->body !== '') {
            return $request->encapsulatedResponse->body;
        }
        if ($request->encapsulatedRequest?->body !== null && $request->encapsulatedRequest->body !== '') {
            return $request->encapsulatedRequest->body;
        }
        return null;
    }

    /**
     * Which encapsulated section the body belongs to, per RFC 3507 §4.4.
     * A RESPMOD with a response body uses `res-body`; a REQMOD with a
     * request body uses `req-body`. An encapsulated response body wins
     * over a request body when both are present (the response is what
     * gets modified on the way back).
     */
    private function bodyCarrier(IcapRequest $request): string
    {
        if ($request->encapsulatedResponse?->body !== null && $request->encapsulatedResponse->body !== '') {
            return 'res-body';
        }
        return 'req-body';
    }

    private function buildEncapsulatedHeader(
        bool $hasReqHeaders,
        int $reqHeaderLength,
        bool $hasResHeaders,
        int $resHeaderLength,
        string $bodyCarrier,
        bool $hasBody,
    ): string {
        $parts = [];
        $offset = 0;

        if ($hasReqHeaders) {
            $parts[] = "req-hdr={$offset}";
            $offset += $reqHeaderLength;
        }
        if ($hasResHeaders) {
            $parts[] = "res-hdr={$offset}";
            $offset += $resHeaderLength;
        }

        if ($hasBody) {
            $parts[] = "{$bodyCarrier}={$offset}";
        } else {
            $parts[] = "null-body={$offset}";
        }

        return implode(', ', $parts);
    }

    private function renderIcapHead(IcapRequest $request, string $encapsulated): string
    {
        $parts = parse_url($request->uri);
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        $requestLine = sprintf('%s %s ICAP/1.0', $request->method, $request->uri);

        $headers = $request->headers;
        if (!isset($headers['Host'])) {
            $headers['Host'] = [$host];
        }
        // Until the transport layer learns to detect end-of-response
        // from the Encapsulated header (scheduled with keep-alive
        // pooling in M3 follow-up), default to Connection: close so
        // the server closes the socket after answering and our read
        // loop sees EOF instead of waiting for the stream timeout.
        // RFC 3507 §5.5 explicitly permits Connection: close.
        if (!isset($headers['Connection'])) {
            $headers['Connection'] = ['close'];
        }
        // Encapsulated is computed by the formatter — any user-supplied
        // value would contradict the actual byte layout.
        $headers['Encapsulated'] = [$encapsulated];

        $head = $requestLine . "\r\n";
        // Emit Host first for readability, then the remaining headers in
        // the order the caller supplied them, then Encapsulated last
        // (it's the header ICAP parsers rely on).
        $orderedNames = array_unique(array_merge(['Host'], array_keys($headers), ['Encapsulated']));
        foreach ($orderedNames as $name) {
            foreach ($headers[$name] as $value) {
                $head .= $name . ': ' . $value . "\r\n";
            }
        }
        $head .= "\r\n";

        return $head;
    }

    private function renderHttpRequestHeaders(HttpRequest $req): string
    {
        $block = sprintf('%s %s %s', $req->method, $req->requestTarget, $req->httpVersion) . "\r\n";
        foreach ($req->headers as $name => $values) {
            foreach ($values as $value) {
                $block .= $name . ': ' . $value . "\r\n";
            }
        }
        $block .= "\r\n";

        return $block;
    }

    private function renderHttpResponseHeaders(HttpResponse $res): string
    {
        $block = sprintf('%s %d %s', $res->httpVersion, $res->statusCode, $res->reasonPhrase) . "\r\n";
        foreach ($res->headers as $name => $values) {
            foreach ($values as $value) {
                $block .= $name . ': ' . $value . "\r\n";
            }
        }
        $block .= "\r\n";

        return $block;
    }

    /**
     * Emit the body as HTTP/1.1 chunked transfer encoding.
     *
     * Strings are sent as a single chunk; resources are read in
     * self::CHUNK_SIZE blocks so a multi-gigabyte body never needs to
     * reside in memory.
     *
     * @param string|resource $body
     * @return iterable<string>
     */
    private function chunkBody(mixed $body, bool $previewIsComplete): iterable
    {
        $terminator = $previewIsComplete ? "0; ieof\r\n\r\n" : "0\r\n\r\n";

        if (is_string($body)) {
            if ($body !== '') {
                yield dechex(strlen($body)) . "\r\n" . $body . "\r\n";
            }
            yield $terminator;
            return;
        }

        if (!is_resource($body)) {
            throw new \InvalidArgumentException(
                'Encapsulated HTTP body must be a string or a readable stream resource.',
            );
        }

        rewind($body);
        while (!feof($body)) {
            $chunk = fread($body, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
        yield $terminator;
    }
}

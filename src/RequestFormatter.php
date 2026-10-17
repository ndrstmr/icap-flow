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
 * Methods, URI, request and status lines and every header name and value
 * are checked when {@see format()} is called, before the first chunk
 * exists. Invalid metadata throws {@see \InvalidArgumentException}, so not
 * a single byte of such a request reaches the socket. Body bytes are
 * binary and pass unchecked.
 *
 * A stream body can only be measured while it is read. When its message
 * announces a length in a single plain `Content-Length` field, the stream
 * has to deliver exactly that many bytes: the chunks are produced lazily,
 * and a stream that is shorter or longer raises a \RuntimeException before
 * the final chunk is written, instead of ending as a complete body of
 * another size. That holds for a whole body and for a preview that ends in
 * `ieof`. A preview that carries only the start of the body is not held to
 * it: the field still describes the whole body, which follows after
 * `100 Continue`. A message without such a field is sent as it is.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3507#section-4
 */
final class RequestFormatter implements RequestFormatterInterface
{
    /**
     * @throws \InvalidArgumentException When the request metadata cannot be
     *         sent as given
     */
    #[\Override]
    public function format(IcapRequest $request): iterable
    {
        // Render and check every header block before handing out the first
        // chunk: an invalid request fails here, not after its ICAP head has
        // reached the socket. The block lengths also give the `*-body`
        // offset, which MUST appear in the Encapsulated header before the
        // body is written on the wire (RFC 3507 §4.4.1).
        $reqHeaderBlock = $request->encapsulatedRequest !== null
            ? $this->renderHttpRequestHeaders($request->encapsulatedRequest)
            : '';
        $resHeaderBlock = $request->encapsulatedResponse !== null
            ? $this->renderHttpResponseHeaders($request->encapsulatedResponse)
            : '';

        $bodySource = $this->resolveBodySource($request);
        $bodyLength = is_resource($bodySource) ? $this->announcedLength($request) : null;

        $encapsulated = $this->buildEncapsulatedHeader(
            hasReqHeaders: $reqHeaderBlock !== '',
            reqHeaderLength: strlen($reqHeaderBlock),
            hasResHeaders: $resHeaderBlock !== '',
            resHeaderLength: strlen($resHeaderBlock),
            bodyCarrier: $this->bodyCarrier($request),
            hasBody: $bodySource !== null,
        );

        $icapHead = $this->renderIcapHead($request, $encapsulated);

        return $this->emit(
            $icapHead,
            $reqHeaderBlock,
            $resHeaderBlock,
            $bodySource,
            $bodyLength,
            $request->previewIsComplete,
        );
    }

    /**
     * Yield the rendered header blocks, then stream the body.
     *
     * @param  string|resource|null $bodySource
     * @param  int|null             $bodyLength bytes a stream body has to deliver
     * @return iterable<string>
     */
    private function emit(
        string $icapHead,
        string $reqHeaderBlock,
        string $resHeaderBlock,
        mixed $bodySource,
        ?int $bodyLength,
        bool $previewIsComplete,
    ): iterable {
        yield $icapHead;

        if ($reqHeaderBlock !== '') {
            yield $reqHeaderBlock;
        }
        if ($resHeaderBlock !== '') {
            yield $resHeaderBlock;
        }

        if ($bodySource !== null) {
            yield from $this->chunkBody($bodySource, $bodyLength, $previewIsComplete);
        }
    }

    /**
     * @return string|resource|null
     */
    private function resolveBodySource(IcapRequest $request): mixed
    {
        $message = $this->bodyMessage($request);
        if ($message !== null) {
            return $message->body;
        }
        return $this->isEmptyPreview($request) ? '' : null;
    }

    /**
     * The encapsulated message whose body goes out. A response body wins
     * over a request body when both are present.
     */
    private function bodyMessage(IcapRequest $request): HttpRequest|HttpResponse|null
    {
        if ($request->encapsulatedResponse?->body !== null && $request->encapsulatedResponse->body !== '') {
            return $request->encapsulatedResponse;
        }
        if ($request->encapsulatedRequest?->body !== null && $request->encapsulatedRequest->body !== '') {
            return $request->encapsulatedRequest;
        }
        return null;
    }

    /**
     * The length the message that carries the body announces for it: a single
     * `Content-Length` value, in any spelling of the field, that is a plain
     * decimal number. Several differing values or anything else leave the
     * message without an announced length, and its stream is not held to one.
     * Neither is the stream of a partial preview: it is the start of the body
     * that the field describes, not all of it.
     */
    private function announcedLength(IcapRequest $request): ?int
    {
        if ($this->isPartialPreview($request)) {
            return null;
        }

        $message = $this->bodyMessage($request);
        if ($message === null) {
            return null;
        }

        $values = array_values(array_unique(
            HeaderFields::values(HeaderFields::validated($message->headers, 'HTTP'), 'Content-Length'),
        ));
        if (count($values) !== 1 || preg_match('/^[0-9]{1,18}\z/', $values[0]) !== 1) {
            return null;
        }

        return (int) $values[0];
    }

    /**
     * Whether the body of a request is only the start of the message: it
     * announces a `Preview` and does not end in `ieof` (RFC 3507 §4.5). The
     * rest follows after `100 Continue`.
     */
    private function isPartialPreview(IcapRequest $request): bool
    {
        return !$request->previewIsComplete
            && HeaderFields::first($request->headers, ['Preview']) !== null;
    }

    /**
     * `Preview: 0` announces a body whose preview is empty: its chunk stream
     * ends at once and the server answers `100 Continue` for the rest
     * (RFC 3507 §4.5). An empty string must not read as "no body" there: the
     * message would go out as `null-body`, and a server could answer 204 for
     * a body it was never offered.
     */
    private function isEmptyPreview(IcapRequest $request): bool
    {
        return !$request->previewIsComplete
            && HeaderFields::first($request->headers, ['Preview']) === '0'
            && ($request->encapsulatedResponse?->body === '' || $request->encapsulatedRequest?->body === '');
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
        $response = $request->encapsulatedResponse;
        if ($response?->body !== null && ($response->body !== '' || $this->isEmptyPreview($request))) {
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
        if (!HeaderFields::isToken($request->method)) {
            throw new \InvalidArgumentException('ICAP method must be a token.');
        }
        $host = IcapUri::authority($request->uri);

        // Host and Encapsulated describe this very message, so they come
        // from the URI and the computed byte layout only. A caller value
        // for either is dropped in any spelling instead of replacing them
        // or going out as a second field.
        $headers = HeaderFields::without(
            HeaderFields::validated($request->headers, 'ICAP'),
            ['Host', 'Encapsulated'],
        );

        // Host first for readability, then the remaining headers in the
        // order the caller supplied them, then Encapsulated last (it's the
        // header ICAP parsers rely on).
        return sprintf('%s %s ICAP/1.0', $request->method, $request->uri) . "\r\n"
            . 'Host: ' . $host . "\r\n"
            . $this->renderFields($headers)
            . 'Encapsulated: ' . $encapsulated . "\r\n"
            . "\r\n";
    }

    private function renderHttpRequestHeaders(HttpRequest $req): string
    {
        // request-line = method SP request-target SP HTTP-version (RFC 7230 §3.1.1)
        if (!HeaderFields::isToken($req->method)
            || preg_match('/^[^\x00-\x20\x7F]+\z/', $req->requestTarget) !== 1
            || !$this->isHttpVersion($req->httpVersion)
        ) {
            throw new \InvalidArgumentException('Encapsulated HTTP request line is invalid (RFC 7230 §3.1.1).');
        }

        return sprintf('%s %s %s', $req->method, $req->requestTarget, $req->httpVersion) . "\r\n"
            . $this->renderFields(HeaderFields::validated($req->headers, 'HTTP'))
            . "\r\n";
    }

    private function renderHttpResponseHeaders(HttpResponse $res): string
    {
        // status-line = HTTP-version SP status-code SP reason-phrase (RFC 7230 §3.1.2)
        if (!$this->isHttpVersion($res->httpVersion)
            || $res->statusCode < 100
            || $res->statusCode > 999
            || !HeaderFields::isText($res->reasonPhrase)
        ) {
            throw new \InvalidArgumentException('Encapsulated HTTP status line is invalid (RFC 7230 §3.1.2).');
        }

        return sprintf('%s %d %s', $res->httpVersion, $res->statusCode, $res->reasonPhrase) . "\r\n"
            . $this->renderFields(HeaderFields::validated($res->headers, 'HTTP'))
            . "\r\n";
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function renderFields(array $headers): string
    {
        $block = '';
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $block .= $name . ': ' . $value . "\r\n";
            }
        }

        return $block;
    }

    /**
     * HTTP-version = HTTP-name "/" DIGIT "." DIGIT (RFC 7230 §2.6).
     */
    private function isHttpVersion(string $version): bool
    {
        return preg_match('#^HTTP/[0-9]\.[0-9]\z#', $version) === 1;
    }

    /**
     * Emit the body as HTTP/1.1 chunked transfer encoding. Delegates
     * to {@see ChunkedBodyEncoder} so the strict RFC 3507 §4.5
     * preview-continue path on the same socket can reuse the exact
     * same encoding rules.
     *
     * @param string|resource $body
     * @param int|null        $length bytes a stream body has to deliver
     * @return iterable<string>
     */
    private function chunkBody(mixed $body, ?int $length, bool $previewIsComplete): iterable
    {
        return (new ChunkedBodyEncoder())->encode($body, $previewIsComplete, $length);
    }
}

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

use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Transport\ResponseFrameReader;

/**
 * The transport must learn when an ICAP response ends WITHOUT relying
 * on the server closing the connection. Servers are allowed (and
 * default) to hold the connection open for keep-alive (RFC 3507 §5.5).
 *
 * The framing is unambiguous from the response itself:
 *   1. ICAP head ends at the first `\r\n\r\n`.
 *   2. The Encapsulated header tells us where the body starts (or
 *      that there is no body — `null-body=N`).
 *   3. If a body is present, it is HTTP/1.1 chunked-encoded, so the
 *      message ends right after the chunked terminator
 *      `0\r\n\r\n` (or `0; ieof\r\n\r\n`).
 */

it('returns the response when the server keeps the connection open after a null-body OPTIONS reply', function () {
    $bytes = "ICAP/1.0 200 OK\r\n"
        . "Methods: RESPMOD\r\n"
        . "ISTag: \"abc\"\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('returns the response when the encapsulated body has a 0-chunk terminator', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 5\r\n\r\n";
    $bytes = "ICAP/1.0 200 OK\r\n"
        . "ISTag: \"x\"\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "5\r\nhello\r\n0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('handles fragmented arrivals — header split across two reads', function () {
    $bytes = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([
        "ICAP/1.0 204 No Co",
        "ntent\r\nEncapsulated: null-body=0\r\n",
        "\r\n",
    ]));

    expect($response)->toBe($bytes);
});

it('does not consume bytes past the response — the next request can use the same socket', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
    $first = "ICAP/1.0 200 OK\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "5\r\nhello\r\n0\r\n\r\n";
    $second = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    // The producer is given the two responses concatenated as a single
    // chunk — the reader must stop consuming after the first one.
    $producer = makeChunkProducer([$first . $second]);

    $response = $reader->readFrom($producer);

    expect($response)->toBe($first);
});

it('aborts when the response exceeds maxResponseSize', function () {
    $reader = new ResponseFrameReader(maxResponseSize: 32, maxHeaderLineLength: 8192);
    expect(fn () => $reader->readFrom(makeChunkProducer([str_repeat('A', 100)])))
        ->toThrow(IcapMalformedResponseException::class, 'max size');
});

it('raises a malformed-response exception on EOF before any separator', function () {
    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    expect(fn () => $reader->readFrom(makeChunkProducer(['ICAP/1.0 200 OK'])))
        ->toThrow(IcapMalformedResponseException::class);
});

/**
 * v2.2-R — RFC 7230 §3.2.4 obs-fold support in the Encapsulated header.
 *
 * Some ICAP servers (notably c-icap under certain configurations) fold
 * long headers across multiple lines using obs-fold: a continuation
 * line starts with at least one SP or HTAB. The reader must unfold
 * these before parsing the Encapsulated header value.
 */

it('handles obs-fold (continuation line) in the Encapsulated header — SP prefix', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n";
    // Encapsulated value is folded across two lines with a leading SP.
    $bytes = "ICAP/1.0 200 OK\r\n"
        . "ISTag: \"fold\"\r\n"
        . "Encapsulated: res-hdr=0,\r\n"
        . " res-body=" . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "3\r\nabc\r\n0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('handles obs-fold (continuation line) in the Encapsulated header — HTAB prefix', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n";
    // Folded with HTAB instead of SP.
    $bytes = "ICAP/1.0 200 OK\r\n"
        . "Encapsulated: res-hdr=0,\r\n"
        . "\tres-body=" . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "3\r\nxyz\r\n0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('handles obs-fold in the Encapsulated header with null-body', function () {
    // null-body on a continuation line.
    $bytes = "ICAP/1.0 204 No Content\r\n"
        . "Encapsulated:\r\n"
        . " null-body=0\r\n"
        . "\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

/**
 * @param list<string> $chunks
 * @return Closure(): ?string
 */
function makeChunkProducer(array $chunks): Closure
{
    $i = 0;
    return static function () use (&$i, $chunks): ?string {
        if ($i >= count($chunks)) {
            return null;
        }
        return $chunks[$i++];
    };
}

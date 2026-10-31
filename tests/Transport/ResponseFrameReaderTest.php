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

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\ResponseParser;
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

it('stops at the end of the response and asks for no bytes behind it', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
    $first = "ICAP/1.0 200 OK\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "5\r\nhello\r\n0\r\n\r\n";
    $second = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $asked = 0;
    $producer = static function () use (&$asked, $first, $second): ?string {
        return [$first, $second][$asked++] ?? null;
    };

    expect($reader->readFrom($producer))->toBe($first);
    // The response ended with the first read: the reader does not wait for what may come behind it.
    expect($asked)->toBe(1);
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

it('handles 0; ieof chunked terminator from a complete-in-preview response', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
    $bytes = "ICAP/1.0 200 OK\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "5\r\nhello\r\n0; ieof\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('handles the REQMOD response layout req-hdr + req-body', function () {
    $reqHdr = "POST /upload HTTP/1.1\r\nHost: example.com\r\nContent-Length: 4\r\n\r\n";
    $bytes = "ICAP/1.0 200 OK\r\n"
        . 'Encapsulated: req-hdr=0, req-body=' . strlen($reqHdr) . "\r\n"
        . "\r\n"
        . $reqHdr
        . "4\r\ndata\r\n0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $response = $reader->readFrom(makeChunkProducer([$bytes]));

    expect($response)->toBe($bytes);
});

it('rejects conflicting Encapsulated fields instead of framing by the first one', function () {
    $http = "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n";
    $bytes = "ICAP/1.0 200 OK\r\n"
        . "encapsulated: null-body=0\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($http) . "\r\n"
        . "\r\n"
        . $http
        . "3\r\nabc\r\n0\r\n\r\n";

    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);

    expect(fn () => $reader->readFrom(makeChunkProducer([$bytes])))
        ->toThrow(IcapMalformedResponseException::class);
});

/**
 * M16: the transport reads the head before the parser does, so a header line
 * without a colon is rejected here first; the message must not repeat it, and
 * is the same whatever the line was.
 */
it('keeps a received header line out of the exception message', function () {
    $message = static function (string $head): string {
        $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
        try {
            $reader->readFrom(makeChunkProducer(["ICAP/1.0 200 OK\r\n$head\r\nEncapsulated: null-body=0\r\n\r\n"]));
        } catch (IcapMalformedResponseException $e) {
            return $e->getMessage();
        }

        throw new LogicException('The reader accepted a head that is malformed.');
    };

    $messages = [
        $message('SECRET-HEADER-5c1e without a colon'),
        $message('SECRET-HEADER-5c1e'),
        $message('another line, of another length'),
    ];

    expect(count(array_unique($messages)))->toBe(1);
    expect(str_contains($messages[0], 'SECRET'))->toBe(false);
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

/*
 * B02/B03/M03: the end of a response is where its layout says, and only there.
 * A chunk payload may hold anything, a terminator or a whole forged response;
 * a response that cannot be framed to its end, byte for byte, is no response.
 * The parser reads a complete response with the same rules, so a response is
 * accepted or refused alike by the framing of the transports and by the parser,
 * and in the same way however the bytes arrive.
 */

it('frames a legal response to its last byte, at every split position and in every fragment size', function () {
    $parser = new ResponseParser();

    foreach (rfLegalResponses() as $label => [$wire, $status, $body]) {
        $expected = $parser->parse($wire);
        expect($expected->statusCode)->toBe($status, $label);
        expect($expected->body)->toBe($body, $label);

        foreach (rfFragmentations($wire, $label) as $how => $chunks) {
            $frame = (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readFrom(makeChunkProducer($chunks));

            expect($frame)->toBe($wire, "{$label}, {$how}");
            expect($parser->parse($frame))->toEqual($expected, "{$label}, {$how}");

            // Received without being kept (AP-15): the same response, its body counted instead of held.
            $dropped = (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readDiscardingBody(makeChunkProducer($chunks), 1 << 20);
            expect($dropped)->toEqual(new IcapResponse($expected->statusCode, $expected->headers, '', strlen($body), $expected->encapsulatedStatus), "{$label}, {$how}, discarding");
        }
    }
});

it('refuses an illegal response, in the parser and in the framing, however it arrives', function () {
    foreach (rfIllegalResponses() as $label => $wire) {
        expect(fn () => (new ResponseParser())->parse($wire))
            ->toThrow(IcapMalformedResponseException::class, '', $label);

        foreach (rfFragmentations($wire, $label) as $how => $chunks) {
            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readFrom(makeChunkProducer($chunks)))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label}, {$how}");
            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readDiscardingBody(makeChunkProducer($chunks), 1 << 20))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label}, {$how}, discarding");
        }
    }
});

it('takes the end of the stream inside a legal response for malformed, at every position', function () {
    foreach (rfLegalResponses() as $label => [$wire]) {
        for ($cut = 0; $cut < strlen($wire); $cut++) {
            $prefix = substr($wire, 0, $cut);

            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readFrom(makeChunkProducer($prefix === '' ? [] : [$prefix])))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label}, cut after {$cut} bytes");
            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readDiscardingBody(makeChunkProducer($prefix === '' ? [] : [$prefix]), 1 << 20))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label}, cut after {$cut} bytes, discarding");
            expect(fn () => (new ResponseParser())->parse($prefix))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label}, cut after {$cut} bytes");
        }
    }
});

/*
 * A connection carries one outstanding request at a time (RFC 3507 §4.1): bytes that came in behind a response answer
 * nothing, whether the response is kept or not, and a clean answer with bytes behind it is no verdict.
 */
it('refuses a response, kept or dropped, when bytes of another one came in behind it', function () {
    $labels = array_keys(rfLegalResponses());
    $legal = array_column(rfLegalResponses(), 0);

    foreach ($labels as $i => $label) {
        $first = $legal[$i];
        $wire = $first . $legal[($i + 1) % count($labels)];

        // However much of the second response came in with the end of the first.
        for ($cut = strlen($first) + 1; $cut <= strlen($wire); $cut++) {
            $chunks = [substr($wire, 0, $cut), substr($wire, $cut)];

            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))->readFrom(makeChunkProducer($chunks)))
                ->toThrow(IcapMalformedResponseException::class, 'bytes', "{$label}, cut after {$cut} bytes");
            expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))->readDiscardingBody(makeChunkProducer($chunks), 1 << 20))
                ->toThrow(IcapMalformedResponseException::class, 'bytes', "{$label}, cut after {$cut} bytes, discarding");
        }
    }
});

it('does not take a terminator or a forged response inside a chunk payload for the end of the message', function () {
    $forged = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $payload = "prefix\r\n0\r\n\r\n" . $forged . 'suffix';
    $head = rfHttpHead();
    $wire = rfIcap('200 OK', 'res-hdr=0, res-body=' . strlen($head), $head . dechex(strlen($payload)) . "\r\n" . $payload . "\r\n0\r\n\r\n");

    // The first read ends right behind the terminator that sits in the payload.
    $cut = strpos($wire, "prefix\r\n0\r\n\r\n") + strlen("prefix\r\n0\r\n\r\n");
    $reader = new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192);
    $reads = 0;
    $producer = static function () use (&$reads, $wire, $cut): ?string {
        return match ($reads++) {
            0 => substr($wire, 0, $cut),
            1 => substr($wire, $cut),
            default => null,
        };
    };

    $frame = $reader->readFrom($producer);

    expect($frame)->toBe($wire);
    expect($reads)->toBe(2);
    expect((new ResponseParser())->parse($frame)->body)->toBe($payload);

    // A response that is not kept is read to the same end: the terminator in the payload is payload.
    $reads = 0;
    $dropped = (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))->readDiscardingBody($producer, 1 << 20);
    expect($reads)->toBe(2)
        ->and($dropped->discardedBodyBytes)->toBe(strlen($payload));
});

it('reads the same decision from the parser and from the framing for every response', function () {
    // Whatever the framing returns, the parser reads it as the same response; what the parser
    // refuses, the framing refuses too.
    $parser = new ResponseParser();

    foreach (array_merge(array_column(rfLegalResponses(), 0), array_values(rfIllegalResponses())) as $i => $wire) {
        $parsed = null;
        $parseError = null;
        try {
            $parsed = $parser->parse($wire);
        } catch (IcapMalformedResponseException $e) {
            $parseError = $e::class;
        }

        $framed = null;
        $frameError = null;
        try {
            $framed = (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 8192))
                ->readFrom(makeChunkProducer([$wire]));
        } catch (IcapMalformedResponseException $e) {
            $frameError = $e::class;
        }

        expect($frameError)->toBe($parseError, "response {$i}");
        expect($framed)->toBe($parsed === null ? null : $wire, "response {$i}");
    }
});

/*
 * The limits that bound a header line and a block of header lines apply to every line the
 * response carries, not just to the ICAP head: the lines of an encapsulated header section,
 * the chunk size lines with their extensions and the trailer.
 */
it('applies the line length and header count limits to the whole response', function () {
    $long = str_repeat('a', 60);
    $head = "HTTP/1.1 200 OK\r\nX-A: 1\r\n\r\n";
    $headLong = "HTTP/1.1 200 OK\r\nX-A: {$long}\r\n\r\n";
    $headMany = "HTTP/1.1 200 OK\r\nX-A: 1\r\nX-B: 2\r\nX-C: 3\r\nX-D: 4\r\n\r\n";
    $section = static fn (string $http, string $rest): string => rfIcap('200 OK', 'res-hdr=0, res-body=' . strlen($http), $http . $rest);

    // label => [wire, whether the limits (lines up to 48 bytes, at most 3 header lines per block) let it pass]
    $cases = [
        'control: header section within the limits'  => [$section($head, "3\r\nabc\r\n0\r\n\r\n"), true],
        'control: chunk extension within the limits' => [rfIcap('200 OK', 'res-body=0', "3;a=b\r\nabc\r\n0\r\n\r\n"), true],
        'control: trailer at the limits'             => [rfIcap('200 OK', 'res-body=0', "3\r\nabc\r\n0\r\nX-A: 1\r\nX-B: 2\r\nX-C: 3\r\n\r\n"), true],
        'ICAP header line too long'                  => [rfIcap('200 OK', 'null-body=0', '', "X-A: {$long}\r\n"), false],
        'too many ICAP header lines'                 => [rfIcap('200 OK', 'null-body=0', '', "X-A: 1\r\nX-B: 2\r\nX-C: 3\r\n"), false],
        'line of the header section too long'        => [$section($headLong, "3\r\nabc\r\n0\r\n\r\n"), false],
        'too many lines in the header section'       => [$section($headMany, "3\r\nabc\r\n0\r\n\r\n"), false],
        'chunk size line too long'                   => [rfIcap('200 OK', 'res-body=0', "3;a={$long}\r\nabc\r\n0\r\n\r\n"), false],
        'last chunk line too long'                   => [rfIcap('200 OK', 'res-body=0', "3\r\nabc\r\n0;a={$long}\r\n\r\n"), false],
        'trailer line too long'                      => [rfIcap('200 OK', 'res-body=0', "3\r\nabc\r\n0\r\nX-A: {$long}\r\n\r\n"), false],
        'too many trailer fields'                    => [rfIcap('200 OK', 'res-body=0', "3\r\nabc\r\n0\r\nX-A: 1\r\nX-B: 2\r\nX-C: 3\r\nX-D: 4\r\n\r\n"), false],
    ];

    foreach ($cases as $label => [$wire, $passes]) {
        $readers = [
            'parser'  => fn () => (new ResponseParser(maxHeaderCount: 3, maxHeaderLineLength: 48))->parse($wire),
            'framing' => fn () => (new ResponseFrameReader(maxResponseSize: 1 << 20, maxHeaderLineLength: 48, maxHeaderCount: 3))
                ->readFrom(makeChunkProducer([$wire])),
            'discarding' => fn () => (new ResponseFrameReader(maxResponseSize: 1, maxHeaderLineLength: 48, maxHeaderCount: 3))
                ->readDiscardingBody(makeChunkProducer([$wire]), 1 << 20),
        ];
        foreach ($readers as $by => $read) {
            if ($passes) {
                expect($read())->not->toBeNull("{$label}, {$by}");
            } else {
                expect($read)->toThrow(IcapMalformedResponseException::class, '', "{$label}, {$by}");
            }
        }
    }
});

it('gives up on a chunk that cannot fit before it reads any of its data', function () {
    $reads = 0;
    $reader = new ResponseFrameReader(maxResponseSize: 200, maxHeaderLineLength: 8192);
    $head = rfIcap('200 OK', 'res-body=0', "10000\r\n");
    $producer = static function () use (&$reads, $head): string {
        return $reads++ === 0 ? $head : str_repeat('x', 100);
    };

    expect(fn () => $reader->readFrom($producer))->toThrow(IcapMalformedResponseException::class, 'max size');
    // The size line was enough to know: the data was not waited for.
    expect($reads)->toBe(1);

    // The same holds for the budget of a response that is not kept.
    $reads = 0;
    expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 30, maxHeaderLineLength: 8192))->readDiscardingBody($producer, 200))
        ->toThrow(IcapMalformedResponseException::class, 'max size');
    expect($reads)->toBe(1);

    // Bytes that are in but not used yet count against it as well, next to the ones that were used and dropped:
    // the unfinished trailer line is well within the budget on its own.
    $used = rfIcap('200 OK', 'res-body=0', "5\r\nhello\r\n0\r\n");
    $parts = [$used, 'X-A: ' . str_repeat('a', 60)];
    $reads = 0;
    $producer = static function () use (&$reads, $parts): ?string {
        return $parts[$reads++] ?? null;
    };
    expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 30, maxHeaderLineLength: 8192))->readDiscardingBody($producer, strlen($used) + 40))
        ->toThrow(IcapMalformedResponseException::class, 'max size');
    expect($reads)->toBe(2);
});

it('refuses a complete response that is over the size limit, however it arrives, and takes one at the limit', function () {
    $wire = rfIcap('200 OK', 'res-body=0', "5\r\nhello\r\n0\r\n\r\n");

    foreach (['in one piece' => [$wire], 'in parts' => str_split($wire, 7)] as $how => $chunks) {
        expect(fn () => (new ResponseFrameReader(maxResponseSize: strlen($wire) - 1, maxHeaderLineLength: 8192))
            ->readFrom(makeChunkProducer($chunks)))
            ->toThrow(IcapMalformedResponseException::class, 'max size', $how);
        expect((new ResponseFrameReader(maxResponseSize: strlen($wire), maxHeaderLineLength: 8192))
            ->readFrom(makeChunkProducer($chunks)))
            ->toBe($wire, $how);

        // The budget of a response that is not kept counts every byte of it, framing included, and is not the
        // maximum size of the reader.
        expect(fn () => (new ResponseFrameReader(maxResponseSize: 1 << 30, maxHeaderLineLength: 8192))
            ->readDiscardingBody(makeChunkProducer($chunks), strlen($wire) - 1))
            ->toThrow(IcapMalformedResponseException::class, 'max size', "{$how}, discarding");
        expect((new ResponseFrameReader(maxResponseSize: 1, maxHeaderLineLength: 8192))
            ->readDiscardingBody(makeChunkProducer($chunks), strlen($wire))->discardedBodyBytes)
            ->toBe(5, "{$how}, discarding");
    }
});

/**
 * An HTTP header block as an encapsulated section.
 */
function rfHttpHead(string $status = '200 OK'): string
{
    return "HTTP/1.1 {$status}\r\nContent-Type: application/octet-stream\r\nContent-Length: 5\r\n\r\n";
}

/**
 * An ICAP response from a status, the Encapsulated value, the bytes behind the head and extra header lines.
 */
function rfIcap(string $status, string $encapsulated, string $rest = '', string $headers = ''): string
{
    return "ICAP/1.0 {$status}\r\n{$headers}Encapsulated: {$encapsulated}\r\n\r\n{$rest}";
}

/**
 * Responses RFC 3507 allows (§4.3.3, §4.4.1, §4.8.2, §4.9.2, §4.10.2), each with the status and the body the
 * parser makes of it. The layouts are the response forms: [req-hdr] req-body, [res-hdr] res-body, opt-body and
 * null-body for any of them; a 100, a 204 and an error may leave out the Encapsulated header.
 *
 * @return array<string, array{string, int, string}> label => [wire, status, decoded body]
 */
function rfLegalResponses(): array
{
    $head = rfHttpHead();
    $block = "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n";
    $request = "POST /upload HTTP/1.1\r\nHost: example.com\r\nContent-Length: 4\r\n\r\n";
    $forged = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $payload = "prefix\r\n0\r\n\r\n" . $forged . 'suffix';
    $chunk = static fn (string $data): string => dechex(strlen($data)) . "\r\n" . $data . "\r\n";
    $large = str_repeat('0123456789', 1000);

    return [
        'interim 100 without Encapsulated'              => ["ICAP/1.0 100 Continue\r\n\r\n", 100, ''],
        'interim 100 with null-body=0'                  => [rfIcap('100 Continue', 'null-body=0'), 100, ''],
        '204 without Encapsulated'                      => ["ICAP/1.0 204 No Content\r\nISTag: \"x\"\r\n\r\n", 204, ''],
        '204 with null-body=0'                          => [rfIcap('204 No Content', 'null-body=0', '', "ISTag: \"x\"\r\n"), 204, ''],
        '404 without Encapsulated'                      => ["ICAP/1.0 404 ICAP Service not found\r\n\r\n", 404, ''],
        '503 without Encapsulated'                      => ["ICAP/1.0 503 Service Unavailable\r\nConnection: close\r\n\r\n", 503, ''],
        '404 with null-body=0'                          => [rfIcap('404 ICAP Service not found', 'null-body=0'), 404, ''],
        '404 with an error page behind res-hdr'         => [rfIcap('404 ICAP Service not found', 'res-hdr=0, res-body=' . strlen($head), $head . "5\r\nhello\r\n0\r\n\r\n"), 404, 'hello'],
        '500 with a res-body'                           => [rfIcap('500 Server error', 'res-body=0', "5\r\nhello\r\n0\r\n\r\n"), 500, 'hello'],
        'obs-fold, HTAB and obs-text in header values'  => ["ICAP/1.0 200 OK\r\nX-Violations-Found: 1\r\n\tEicar-Test\r\n\t Resolution=2\r\nX-Note: \tcaf\xE9 \r\nX-Empty:\r\nEncapsulated: res-hdr=0, res-body=" . strlen("HTTP/1.1 200 OK\r\nX-A: one\r\n two\r\nX-B:\tv\r\n\r\n") . "\r\n\r\nHTTP/1.1 200 OK\r\nX-A: one\r\n two\r\nX-B:\tv\r\n\r\n5\r\nhello\r\n0\r\n\r\n", 200, 'hello'],
        'OPTIONS: null-body=0'                          => [rfIcap('200 OK', 'null-body=0', '', "Methods: RESPMOD\r\nPreview: 1024\r\n"), 200, ''],
        'OPTIONS: opt-body=0 with a body'               => [rfIcap('200 OK', 'opt-body=0', $chunk('options text') . "0\r\n\r\n"), 200, 'options text'],
        'RESPMOD: res-hdr and res-body'                 => [rfIcap('200 OK', 'res-hdr=0, res-body=' . strlen($head), $head . "5\r\nhello\r\n0\r\n\r\n"), 200, 'hello'],
        'RESPMOD: res-hdr and null-body (block page)'   => [rfIcap('200 OK', 'res-hdr=0, null-body=' . strlen($block), $block, "X-Virus-Name: EICAR\r\n"), 200, ''],
        'res-body without a section'                    => [rfIcap('200 OK', 'res-body=0', "5\r\nhello\r\n0\r\n\r\n"), 200, 'hello'],
        'REQMOD: req-hdr and req-body'                  => [rfIcap('200 OK', 'req-hdr=0, req-body=' . strlen($request), $request . "4\r\ndata\r\n0\r\n\r\n"), 200, 'data'],
        'REQMOD: req-hdr and null-body'                 => [rfIcap('200 OK', 'req-hdr=0, null-body=' . strlen($request), $request), 200, ''],
        'several chunks, extensions, ieof'              => [rfIcap('200 OK', 'res-body=0', "3;x=1\r\nabc\r\n2 ; y=\"q z\"\r\nde\r\n0; ieof\r\n\r\n"), 200, 'abcde'],
        'uppercase hex, leading zeros, zero chunk 00'   => [rfIcap('200 OK', 'res-body=0', "000A\r\n0123456789\r\n00\r\n\r\n"), 200, '0123456789'],
        'trailer fields'                                => [rfIcap('200 OK', 'res-body=0', "3\r\nabc\r\n0\r\nX-Trailer: yes\r\nX-Other: ok\r\n\r\n"), 200, 'abc'],
        'terminator and forged response in a payload'   => [rfIcap('200 OK', 'res-hdr=0, res-body=' . strlen($head), $head . $chunk($payload) . "0\r\n\r\n"), 200, $payload],
        'obs-fold in the Encapsulated field'            => ["ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0,\r\n res-body=" . strlen($head) . "\r\n\r\n" . $head . "5\r\nhello\r\n0\r\n\r\n", 200, 'hello'],
        'one large chunk'                               => [rfIcap('200 OK', 'res-body=0', $chunk($large) . "0\r\n\r\n"), 200, $large],
        'entry names in upper and mixed case'           => [rfIcap('200 OK', 'RES-HDR=0, Res-Body=' . strlen($head), $head . "5\r\nhello\r\n0\r\n\r\n"), 200, 'hello'],
    ];
}

/**
 * Responses that are none: broken chunks, numbers that do not fit, layouts RFC 3507 does not allow in a
 * response, header sections that do not end where the layout says, a final response without Encapsulated.
 *
 * @return array<string, string> label => wire
 */
function rfIllegalResponses(): array
{
    $head = rfHttpHead();
    $request = "POST /upload HTTP/1.1\r\nHost: example.com\r\nContent-Length: 4\r\n\r\n";
    $body = "5\r\nhello\r\n0\r\n\r\n";
    $res = static fn (string $rest, string $headers = ''): string => rfIcap('200 OK', 'res-body=0', $rest, $headers);
    $layout = static fn (string $encapsulated, string $rest = ''): string => rfIcap('200 OK', $encapsulated, $rest);
    $section = static fn (string $http, string $encapsulated, string $rest = "5\r\nhello\r\n0\r\n\r\n"): string => rfIcap('200 OK', $encapsulated, $http . $rest);
    // A 204 without Encapsulated is complete at the blank line, so what the fields do is all that is tested.
    $fields = static fn (string $lines): string => "ICAP/1.0 204 No Content\r\n{$lines}\r\n";
    $inSection = static function (string $lines) use ($section): string {
        $http = "HTTP/1.1 200 OK\r\n{$lines}\r\n";

        return $section($http, 'res-hdr=0, res-body=' . strlen($http));
    };
    $chunked = "3\r\nabc\r\n0\r\n\r\n";

    return [
        // Chunk sizes: signs, prefixes, blanks, digits that overflow, sizes that do not fit.
        'chunk size -1'                      => $res("-1\r\nabc\r\n0\r\n\r\n"),
        'chunk size ZZ'                      => $res("ZZ\r\nabc\r\n0\r\n\r\n"),
        'chunk size +5'                      => $res("+5\r\nhello\r\n0\r\n\r\n"),
        'chunk size 0x5'                     => $res("0x5\r\nhello\r\n0\r\n\r\n"),
        'chunk size 5 and a space'           => $res("5 \r\nhello\r\n0\r\n\r\n"),
        'chunk size with a leading space'    => $res(" 5\r\nhello\r\n0\r\n\r\n"),
        'empty chunk size line'              => $res("\r\nabc\r\n0\r\n\r\n"),
        'chunk size 2^64'                    => $res("10000000000000000\r\na\r\n0\r\n\r\n"),
        'chunk size 2^64 - 1'                => $res("ffffffffffffffff\r\na\r\n0\r\n\r\n"),
        'chunk size 2^63'                    => $res("8000000000000000\r\na\r\n0\r\n\r\n"),
        'chunk size PHP_INT_MAX'             => $res("7fffffffffffffff\r\na\r\n0\r\n\r\n"),
        'chunk size of 41 digits'            => $res(str_repeat('f', 41) . "\r\na\r\n0\r\n\r\n"),
        'chunk size over the response limit' => $res("200000\r\n" . str_repeat('x', 100)),
        'chunk size and a bare LF'           => $res("3\nabc\r\n0\r\n\r\n"),
        // Chunk data and the CRLF behind it.
        'chunk shorter than announced'       => $res("5\r\nabc\r\n0\r\n\r\n"),
        'chunk longer than announced'        => $res("2\r\nabc\r\n0\r\n\r\n"),
        'no CRLF behind the chunk data'      => $res("3\r\nabcXY0\r\n\r\n"),
        'LF behind the chunk data'           => $res("3\r\nabc\n0\r\n\r\n"),
        'bare CR behind the chunk data'      => $res("3\r\nabc\r0\r\n\r\n"),
        // Chunk extensions.
        'extension without a name'           => $res("3;\r\nabc\r\n0\r\n\r\n"),
        'extension name that is no token'    => $res("3;a b\r\nabc\r\n0\r\n\r\n"),
        'extension with an empty value'      => $res("3;a=\r\nabc\r\n0\r\n\r\n"),
        'extension with an open quote'       => $res("3;a=\"x\r\nabc\r\n0\r\n\r\n"),
        'extension with a control character' => $res("3;a=b\x01\r\nabc\r\n0\r\n\r\n"),
        'last chunk and junk'                => $res("3\r\nabc\r\n0 junk\r\n\r\n"),
        // Trailers.
        'trailer line without a colon'       => $res("3\r\nabc\r\n0\r\nno colon\r\n\r\n"),
        'trailer name with a space'          => $res("3\r\nabc\r\n0\r\nX Bad: v\r\n\r\n"),
        'trailer line that starts blank'     => $res("3\r\nabc\r\n0\r\n folded: v\r\n\r\n"),
        'trailer value with a control byte'  => $res("3\r\nabc\r\n0\r\nX-A: v\x01\r\n\r\n"),
        'trailer ended by a bare LF'         => $res("3\r\nabc\r\n0\r\nX-A: v\n\r\n"),
        // The Encapsulated header: numbers.
        'offset -1'                          => $layout('res-body=-1', $body),
        'offset oops'                        => $layout('res-body=oops', $body),
        'offset 1e3'                         => $layout('res-body=1e3', $body),
        'offset +5'                          => $layout('res-body=+5', $body),
        'offset 0x10'                        => $layout('res-body=0x10', $body),
        'empty offset'                       => $layout('res-body=', $body),
        'blank before the offset'            => $layout('res-body= 0', $body),
        'offset of 20 digits'                => $layout('res-body=99999999999999999999', $body),
        'offset PHP_INT_MAX'                 => $layout('res-body=9223372036854775807', $body),
        'offset over the response limit'     => $section($head, 'res-hdr=0, res-body=2000000'),
        // The Encapsulated header: structure.
        'empty Encapsulated value'           => $layout('', $body),
        'trailing comma'                     => $layout('res-body=0,', $body),
        'empty entry'                        => $section($head, 'res-hdr=0,, res-body=' . strlen($head)),
        'unknown entry'                      => $section($head, 'res-hdr=0, foo-body=' . strlen($head)),
        'header section without a body entry' => $section($head, 'res-hdr=0', ''),
        'body entry before the section'      => $section($head, 'res-body=0, res-hdr=' . strlen($head)),
        'duplicate entry'                    => $section($head, 'res-hdr=0, res-hdr=' . strlen($head) . ', res-body=' . (2 * strlen($head))),
        'two null-body entries'              => $layout('null-body=0, null-body=0'),
        'request layout in a response'       => rfIcap('200 OK', 'req-hdr=0, res-hdr=' . strlen($request) . ', res-body=' . (strlen($request) + strlen($head)), $request . $head . $body),
        'req-hdr with a res-body'            => $section($request, 'req-hdr=0, res-body=' . strlen($request)),
        'res-hdr with a req-body'            => $section($head, 'res-hdr=0, req-body=' . strlen($head)),
        'res-hdr with an opt-body'           => $section($head, 'res-hdr=0, opt-body=' . strlen($head)),
        'first section not at offset 0'      => $section($head, 'res-hdr=5, res-body=' . strlen($head)),
        'body entry behind unaccounted bytes' => $layout('res-body=5', 'xxxxx' . $body),
        'offsets that do not increase'       => $section($head, 'res-hdr=0, res-body=0'),
        'null-body behind unaccounted bytes' => $layout('null-body=5', 'xxxxx'),
        'opt-body behind unaccounted bytes'  => $layout('opt-body=5', 'xxxxx' . $body),
        // The header section must end where the layout says.
        'section ends before the declared length' => $section($head, 'res-hdr=0, res-body=' . (strlen($head) + 10), 'xxxxxxxxxx' . "5\r\nhello\r\n0\r\n\r\n"),
        'section runs past the declared length' => $section($head, 'res-hdr=0, res-body=' . (strlen($head) - 10)),
        'section without a start line'       => $section("garbage\r\n\r\n", 'res-hdr=0, res-body=' . strlen("garbage\r\n\r\n")),
        'request line in a response section' => $section("GET / HTTP/1.1\r\nHost: a\r\n\r\n", 'res-hdr=0, res-body=' . strlen("GET / HTTP/1.1\r\nHost: a\r\n\r\n")),
        'status line in a request section'   => $section($head, 'req-hdr=0, req-body=' . strlen($head)),
        'section line without a colon'       => $section("HTTP/1.1 200 OK\r\nno colon\r\n\r\n", 'res-hdr=0, res-body=' . strlen("HTTP/1.1 200 OK\r\nno colon\r\n\r\n")),
        'section ended by bare LFs'          => $section("HTTP/1.1 200 OK\nContent-Length: 5\n\n", 'res-hdr=0, res-body=' . strlen("HTTP/1.1 200 OK\nContent-Length: 5\n\n")),
        // Header fields (AP10-R01): the name is a token and the value holds no control character, checked
        // on what arrived, before whitespace is trimmed or a fold is joined.
        'header name empty'                  => $fields(": value\r\n"),
        'header name with a space'           => $fields("X Bad: value\r\n"),
        'header name with a space before the colon' => $fields("X-A : value\r\n"),
        'header name with a control character' => $fields("X-Virus-\x01Name: EICAR\r\n"),
        'first header line is a continuation' => $fields(" X-A: value\r\n"),
        'first header line is a HTAB continuation' => $fields("\tX-A: value\r\n"),
        'first header line is a continuation without a colon' => $fields(" folded\r\n"),
        'header value with a control character' => $fields("X-A: v\x01\r\n"),
        'header value ending in NUL'         => $fields("X-A: v\x00\r\n"),
        'header value ending in VT'          => $fields("X-A: v\x0B\r\n"),
        'header value with DEL'              => $fields("X-A: v\x7F\r\n"),
        'control character in a continuation' => $fields("X-A: v\r\n \x01w\r\n"),
        'continuation ending in NUL'         => $fields("X-A: v\r\n w\x00\r\n"),
        'section header name empty'          => $inSection(": value\r\n"),
        'section header name with a space'   => $inSection("X Bad: value\r\n"),
        'section header name with a control character' => $inSection("X-A\x01: value\r\n"),
        'section header value with a control character' => $inSection("X-A: v\x01\r\n"),
        'section header value ending in NUL' => $inSection("X-A: v\x00\r\n"),
        'section control character in a continuation' => $inSection("X-A: v\r\n\t\x01w\r\n"),
        'section continuation ending in VT'  => $inSection("X-A: v\r\n\tw\x0B\r\n"),
        'section starts with a continuation' => $inSection(" X-A: value\r\n"),
        // A 100 and a 204 carry no message (AP10-R02): the layout, when there is one, is null-body=0.
        '204 with a res-body'                => rfIcap('204 No Content', 'res-body=0', $chunked),
        '204 with an opt-body'               => rfIcap('204 No Content', 'opt-body=0', $chunked),
        '204 with res-hdr and res-body'      => rfIcap('204 No Content', 'res-hdr=0, res-body=' . strlen($head), $head . $chunked),
        '204 with res-hdr and null-body'     => rfIcap('204 No Content', 'res-hdr=0, null-body=' . strlen($head), $head),
        '204 with req-hdr and null-body'     => rfIcap('204 No Content', 'req-hdr=0, null-body=' . strlen($request), $request),
        '100 with a res-body'                => rfIcap('100 Continue', 'res-body=0', $chunked),
        '100 with res-hdr and res-body'      => rfIcap('100 Continue', 'res-hdr=0, res-body=' . strlen($head), $head . $chunked),
        '100 with res-hdr and null-body'     => rfIcap('100 Continue', 'res-hdr=0, null-body=' . strlen($head), $head),
        '100 with an opt-body'               => rfIcap('100 Continue', 'opt-body=0', $chunked),
        // A final response needs its layout.
        'final 200 without Encapsulated'     => "ICAP/1.0 200 OK\r\nISTag: \"x\"\r\n\r\n",
        'OPTIONS 200 without Encapsulated, body behind' => "ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\n\r\n" . $body,
        'final 206 without Encapsulated'     => "ICAP/1.0 206 Partial Content\r\n\r\n",
        'conflicting Encapsulated fields'    => "ICAP/1.0 200 OK\r\nEncapsulated: null-body=0\r\nencapsulated: res-body=0\r\n\r\n" . $body,
        // The head.
        'HTTP status line'                   => "HTTP/1.1 200 OK\r\nEncapsulated: null-body=0\r\n\r\n",
        'status code of two digits'          => "ICAP/1.0 20 OK\r\nEncapsulated: null-body=0\r\n\r\n",
        'status code of four digits'         => "ICAP/1.0 2000 OK\r\nEncapsulated: null-body=0\r\n\r\n",
        'status code that is no number'      => "ICAP/1.0 abc OK\r\nEncapsulated: null-body=0\r\n\r\n",
        'no space behind the status code'    => "ICAP/1.0 200OK\r\nEncapsulated: null-body=0\r\n\r\n",
        'empty first line'                   => "\r\nICAP/1.0 204 No Content\r\n\r\n",
        'head ended by bare LFs'             => "ICAP/1.0 204 No Content\nEncapsulated: null-body=0\n\n",
        'header line without a colon'        => "ICAP/1.0 204 No Content\r\nno colon here\r\n\r\n",
        'bare LF inside an ICAP header line' => "ICAP/1.0 204 No Content\r\nX-A: one\nX-B: two\r\n\r\n",
        'bare CR inside an ICAP header line' => "ICAP/1.0 204 No Content\r\nX-A: one\rX-B: two\r\n\r\n",
        'bare LF in a header line of a section' => $section("HTTP/1.1 200 OK\r\nX-A: one\nX-B: two\r\n\r\n", 'res-hdr=0, res-body=' . strlen("HTTP/1.1 200 OK\r\nX-A: one\nX-B: two\r\n\r\n")),
        'control character in the reason phrase' => "ICAP/1.0 204 No\x01 Content\r\n\r\n",
    ];
}

/**
 * The ways a wire string can arrive: every split into two parts (for a long string those near its
 * ends and every 97th, so that the number of runs stays small), one byte at a time, parts of a fixed
 * size and parts of irregular but reproducible sizes.
 *
 * @return \Generator<string, list<string>> label => the parts, in order
 */
function rfFragmentations(string $wire, string $seed): \Generator
{
    $length = strlen($wire);

    yield 'whole' => [$wire];
    for ($at = 1; $at < $length; $at++) {
        if ($length <= 400 || $at <= 150 || $at > $length - 150 || $at % 97 === 0) {
            yield "split at {$at}" => [substr($wire, 0, $at), substr($wire, $at)];
        }
    }
    foreach ([1, 3, 7, 64] as $size) {
        yield "parts of {$size}" => str_split($wire, $size);
    }

    mt_srand(crc32($seed));
    $parts = [];
    for ($offset = 0; $offset < $length;) {
        $take = mt_rand(1, 12);
        $parts[] = substr($wire, $offset, $take);
        $offset += $take;
    }
    yield 'irregular parts' => $parts;
}

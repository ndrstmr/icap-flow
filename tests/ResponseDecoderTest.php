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

namespace Ndrstmr\Icap\Tests;

use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\ResponseDecoder;

/*
 * The decoder is the one place that frames and reads an ICAP response (B02, B03). It takes the
 * bytes as they come, remembers how far it got, and looks at each byte a constant number of
 * times (M03); it says no to a response at the first byte that cannot belong to a legal one.
 * The wire-level cases live with the reader (tests/Transport/ResponseFrameReaderTest.php) and
 * the parser; this file covers what only the decoder shows: progress, early refusal, and work.
 */

it('is complete with the last byte of the frame and keeps what comes after', function () {
    $frame = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n5\r\nhello\r\n0\r\n\r\n";
    $decoder = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192);

    foreach (str_split(substr($frame, 0, -1)) as $byte) {
        $decoder->feed($byte);
        expect($decoder->isComplete())->toBeFalse();
    }
    $decoder->feed(substr($frame, -1));

    expect($decoder->isComplete())->toBeTrue();
    expect($decoder->frame())->toBe($frame);
    expect($decoder->remainder())->toBe('');
    expect($decoder->response()->body)->toBe('hello');

    // Bytes that arrive after the end are not part of the frame, in this feed or in a later one.
    $decoder->feed('ICAP/1.0 ');
    $decoder->feed('204');
    expect($decoder->frame())->toBe($frame);
    expect($decoder->remainder())->toBe('ICAP/1.0 204');

    $coalesced = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192);
    $coalesced->feed($frame . 'next');
    expect($coalesced->frame())->toBe($frame);
    expect($coalesced->remainder())->toBe('next');
});

it('hands out a frame, a response or a remainder only when it has them', function () {
    $decoder = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192);
    $decoder->feed("ICAP/1.0 200 OK\r\n");

    expect(fn () => $decoder->frame())->toThrow(\LogicException::class);
    expect(fn () => $decoder->response())->toThrow(\LogicException::class);
    expect(fn () => $decoder->remainder())->toThrow(\LogicException::class);

    // Framing alone does not collect the body, so there is no response to hand out.
    $framing = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: false);
    $framing->feed("ICAP/1.0 204 No Content\r\n\r\n");
    expect($framing->isComplete())->toBeTrue();
    expect(fn () => $framing->response())->toThrow(\LogicException::class);
});

it('refuses a response at the first byte that cannot belong to a legal one', function () {
    $head = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n";
    $section = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";

    // label => [what is fed first and still fine, the next piece that is refused on arrival, limits]
    $cases = [
        'status line of another protocol' => ['', "HTTP/1.1 200 OK\r\n", []],
        'status code of two digits'       => ['', "ICAP/1.0 20 OK\r\n", []],
        'header line without a colon'     => ["ICAP/1.0 200 OK\r\n", "no colon\r\n\r\n", []],
        'blank line before a status line' => ['', "\r\n", []],
        'chunk size that is no number'    => [$head, "ZZ\r\n", []],
        'chunk size that does not fit'    => [$head, "ffffffffffffffffff\r\n", []],
        'chunk size over the size limit'  => [$head, "100000\r\n", ['maxResponseSize' => 4096]],
        'no CRLF behind the chunk data'   => [$head . "3\r\nabc", "XY", []],
        'trailer without a colon'         => [$head . "3\r\nabc\r\n0\r\n", "no colon\r\n", []],
        'request line in a response section' => ["ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($section) . "\r\n\r\n", "GET / HTTP/1.1\r\n", []],
        'section that ends too early'     => ["ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . (strlen($section) + 10) . "\r\n\r\n" . substr($section, 0, -2), "\r\n", []],
        'header line over the limit'      => ["ICAP/1.0 200 OK\r\nX-A: ", str_repeat('a', 100), ['maxHeaderLineLength' => 32]],
        'chunk line over the limit'       => [$head . "3;a=", str_repeat('a', 100), ['maxHeaderLineLength' => 32]],
        'frame over the size limit'       => [$head . "3\r\nabc\r\n", str_repeat('x', 200), ['maxResponseSize' => 100]],
        'offset that does not fit'        => ['', "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=99999999999999999999\r\n\r\n", []],
        'offsets that do not increase'    => ['', "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=0\r\n\r\n", []],
        'chunk that fits the limit but not what is left of it' => [$head, "1000\r\n", ['maxResponseSize' => 4096]],
        'chunk size when nothing is left' => [$head, "5\r\n", ['maxResponseSize' => strlen($head) + 5]],
        'bare CR behind the chunk data'   => [$head . "3\r\nabc", "\rX", []],
        'bare LF inside a header line'    => ["ICAP/1.0 200 OK\r\nX-A: one\nX-B", ": two\r\n", []],
        'header name with a space'        => ["ICAP/1.0 204 No Content\r\nX Bad: v\r\n", "\r\n", []],
        'header value with a control byte' => ["ICAP/1.0 204 No Content\r\nX-A: v\x01\r\n", "\r\n", []],
        'header block that starts with a fold' => ["ICAP/1.0 204 No Content\r\n", " X-A: v\r\n\r\n", []],
        'body layout on a 204'            => ["ICAP/1.0 204 No Content\r\nEncapsulated: res-body=0\r\n", "\r\n", []],
        'header section on a 100'         => ["ICAP/1.0 100 Continue\r\nEncapsulated: res-hdr=0, null-body=" . strlen($section) . "\r\n", "\r\n", []],
    ];

    foreach ($cases as $case => [$first, $bad, $limits]) {
        $decoder = new ResponseDecoder(
            maxResponseSize: $limits['maxResponseSize'] ?? 1 << 20,
            maxHeaderCount: 100,
            maxHeaderLineLength: $limits['maxHeaderLineLength'] ?? 8192,
        );
        if ($first !== '') {
            $decoder->feed($first);
        }

        // Refused in the very feed that brings the byte, not later when the rest has come.
        expect(fn () => $decoder->feed($bad))->toThrow(IcapMalformedResponseException::class, '', $case);
    }
});

/*
 * M03 — "no byte is examined or copied more than a constant number of times". The decoder counts
 * the bytes it searches, checks or copies. The old framing searched the whole buffer again for
 * every fragment: its work grew with the square of the response. These tests take the count for
 * growing responses and fragment sizes: it grows with the bytes, not with the bytes times the
 * number of fragments.
 */
it('does work in proportion to the bytes of a response, whatever the fragment size', function () {
    $sizes = [50_000, 200_000];
    $work = [];

    foreach ([1, 64, 4096] as $fragment) {
        foreach ($sizes as $size) {
            $frame = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n" . dechex($size) . "\r\n" . str_repeat('x', $size) . "\r\n0\r\n\r\n";
            $decoder = new ResponseDecoder(maxResponseSize: 1 << 22, maxHeaderCount: 100, maxHeaderLineLength: 8192);
            foreach (str_split($frame, $fragment) as $part) {
                $decoder->feed($part);
            }
            expect($decoder->isComplete())->toBeTrue("size {$size}, fragments of {$fragment}");

            $work[$fragment][$size] = $decoder->workedBytes();
            // Each byte is looked at or copied a few times at most.
            expect($work[$fragment][$size])->toBeLessThanOrEqual(3 * strlen($frame), "size {$size}, fragments of {$fragment}");
        }
        // Four times the bytes, about four times the work: no square.
        expect($work[$fragment][200_000])->toBeLessThanOrEqual(5 * $work[$fragment][50_000], "fragments of {$fragment}");
    }
});

it('does work in proportion to the bytes of lines and chunks that arrive one byte at a time', function () {
    // Many header lines, many small chunks and a long chunk size line, each byte on its own.
    $headers = '';
    for ($i = 0; $i < 90; $i++) {
        $headers .= "X-Header-{$i}: " . str_repeat('v', 60) . "\r\n";
    }
    $chunks = str_repeat("1\r\nx\r\n", 3000);
    $frames = [
        'many header lines' => "ICAP/1.0 200 OK\r\n{$headers}Encapsulated: null-body=0\r\n\r\n",
        'many small chunks' => "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n{$chunks}0\r\n\r\n",
        'long extension'    => "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n3;a=" . str_repeat('b', 8000) . "\r\nabc\r\n0\r\n\r\n",
    ];

    foreach ($frames as $case => $frame) {
        // A decoder that discards drops what it has used and must not pay for it: copying what is left each time a
        // byte arrives would make the work grow with the square of a long line.
        foreach (['keeping' => false, 'discarding' => true] as $how => $discard) {
            $decoder = new ResponseDecoder(maxResponseSize: 1 << 22, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: false, discard: $discard);
            foreach (str_split($frame) as $byte) {
                $decoder->feed($byte);
            }

            expect($decoder->isComplete())->toBeTrue("{$case}, {$how}");
            // A few passes over the bytes (search, check, copy, copy of the frame), however small the fragments.
            expect($decoder->workedBytes())->toBeLessThanOrEqual(6 * strlen($frame), "{$case}, {$how}");
        }
    }
});

/*
 * AP-15 (M02): a decoder that discards receives the message without keeping it, so a response is
 * limited by a budget of bytes and not by what fits in memory.
 */
it('discards the message and counts the body, with status, headers and what follows as for a decoder that keeps it', function () {
    $head = "HTTP/1.1 200 OK\r\nContent-Length: 11\r\n\r\n";
    $frame = "ICAP/1.0 200 OK\r\nX-Virus-Name: Eicar\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n"
        . $head . "5\r\nhello\r\n6\r\n world\r\n0\r\nX-T: 1\r\n\r\n";

    $keeping = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192);
    $keeping->feed($frame);
    $discarding = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: false, discard: true);
    foreach (str_split($frame . 'next', 5) as $part) {
        $discarding->feed($part);
    }

    $kept = $keeping->response();
    $dropped = $discarding->response();
    expect($dropped->statusCode)->toBe($kept->statusCode)
        ->and($dropped->headers)->toBe($kept->headers)
        ->and($kept->body)->toBe('hello world')
        ->and($kept->discardedBodyBytes)->toBeNull()
        // An empty body that was dropped and one that was empty differ in this field alone.
        ->and($dropped->body)->toBe('')
        ->and($dropped->discardedBodyBytes)->toBe(11)
        ->and($discarding->remainder())->toBe('next')
        ->and(fn () => $discarding->frame())->toThrow(\LogicException::class);

    $empty = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: false, discard: true);
    $empty->feed("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
    expect($empty->response()->discardedBodyBytes)->toBe(0);

    expect(fn () => new ResponseDecoder(1, 1, 1, collectBody: true, discard: true))->toThrow(\InvalidArgumentException::class);
});

it('holds nothing of a body that passes through it', function () {
    $size = 64 * 1024 * 1024;
    $decoder = new ResponseDecoder(maxResponseSize: 1 << 40, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: false, discard: true);
    $fragment = str_repeat('x', 8192);

    // The head and the size line of one chunk as big as the body, then the body in the fragments of a socket.
    $decoder->feed("ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n" . dechex($size) . "\r\n");
    memory_reset_peak_usage();
    $before = memory_get_usage();
    for ($fed = 0; $fed < $size; $fed += strlen($fragment)) {
        $decoder->feed($fragment);
    }
    $growth = memory_get_peak_usage() - $before;
    $decoder->feed("\r\n0\r\n\r\n");

    expect($decoder->isComplete())->toBeTrue()
        ->and($decoder->response()->discardedBodyBytes)->toBe($size)
        // Nothing grows with the 64 MiB.
        ->and($growth)->toBeLessThan(256 * 1024);
});

it('keeps the status of the encapsulated response, in the mode that keeps the body and in the one that discards it', function () {
    $section = static fn (string $status): string => "HTTP/1.0 {$status}\r\nServer: C-ICAP\r\n\r\n";
    $withSection = static fn (string $status): string => "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($section($status)) . "\r\n\r\n"
        . $section($status) . "5\r\nhello\r\n0\r\n\r\n";

    // [the message, the status of its encapsulated response]
    $cases = [
        'a block page' => [$withSection('403 Forbidden'), 403],
        'an echo' => [$withSection('200 OK'), 200],
        'a status line without a phrase' => [$withSection('503'), 503],
        'a header section without a body' => ["ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, null-body=" . strlen($section('404 Not Found')) . "\r\n\r\n" . $section('404 Not Found'), 404],
        'a body without a header section' => ["ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n5\r\nhello\r\n0\r\n\r\n", null],
        'no message' => ["ICAP/1.0 204 No Content\r\n\r\n", null],
        'an encapsulated request, which has no status' => ["ICAP/1.0 200 OK\r\nEncapsulated: req-hdr=0, null-body=39\r\n\r\nGET /a HTTP/1.1\r\nHost: icap.example\r\n\r\n", null],
    ];
    foreach ($cases as $label => [$wire, $status]) {
        foreach ([false, true] as $discard) {
            $decoder = new ResponseDecoder(maxResponseSize: 1 << 20, maxHeaderCount: 100, maxHeaderLineLength: 8192, collectBody: !$discard, discard: $discard);
            foreach (str_split($wire, 7) as $part) {
                $decoder->feed($part);
            }
            expect($decoder->response()->encapsulatedStatus)->toBe($status, $label . ($discard ? ', discarding' : ''));
        }
    }
});

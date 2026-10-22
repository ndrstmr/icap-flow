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

/**
 * ICAP response parser unit tests — structural edge cases. The wire-level
 * RFC fixtures live in tests/Wire/ResponseParserWireTest.php.
 */

it('parses a basic ICAP 200 response and returns an empty body for null-body', function () {
    // null-body=0 means "no encapsulated body" (RFC 3507 §4.4.1): the
    // message ends at the ICAP blank line.
    $raw = "ICAP/1.0 200 OK\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res)->toBeInstanceOf(IcapResponse::class)
        ->and($res->statusCode)->toBe(200)
        ->and($res->headers['Encapsulated'])->toEqual(['null-body=0'])
        ->and($res->body)->toBe('');
});

it('raises a malformed-response exception on a malformed status line', function () {
    $raw = "HTTP/1.0 200 OK\r\n\r\n";
    $parser = new ResponseParser();
    expect(fn () => $parser->parse($raw))->toThrow(IcapMalformedResponseException::class);
});

it('raises a malformed-response exception on an empty input', function () {
    $parser = new ResponseParser();
    expect(fn () => $parser->parse(''))->toThrow(IcapMalformedResponseException::class);
});

/*
 * B02: a string handed to the parser is one response. What follows its end is no
 * part of it and no body, and the parser does not make a result of the front part
 * and drop the rest: the bytes would belong to the next response, which is not here.
 */
it('refuses bytes behind the end of the response', function () {
    $head = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
    $responses = [
        'null-body=0'          => "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
        'interim 100'          => "ICAP/1.0 100 Continue\r\n\r\n",
        'header section only'  => "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, null-body=" . strlen($head) . "\r\n\r\n" . $head,
        'chunked body'         => "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head . "5\r\nhello\r\n0\r\n\r\n",
    ];

    foreach ($responses as $label => $raw) {
        expect((new ResponseParser())->parse($raw)->statusCode)->toBeInt($label);
        foreach (["X", "\r\n", "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n"] as $extra) {
            expect(fn () => (new ResponseParser())->parse($raw . $extra))
                ->toThrow(IcapMalformedResponseException::class, '', "{$label} followed by " . strlen($extra) . ' bytes');
        }
    }
});

/*
 * F-4-3: a final response says in its Encapsulated header where the message ends. Without
 * the header the bytes behind the head are not a body, and the response is not a clean scan.
 * A 100, a 204 and an error carry nothing and may leave the header out.
 */
it('needs the Encapsulated header of a final 200 or 206 response, and of any status that is not bodyless by itself', function () {
    // label => [raw response, whether the parser accepts it]
    $cases = [
        '200 without Encapsulated'               => ["ICAP/1.0 200 OK\r\nISTag: \"x\"\r\n\r\n", false],
        '200 without Encapsulated, bytes behind'  => ["ICAP/1.0 200 OK\r\n\r\nsome plain body", false],
        '200 with a virus header, no Encapsulated' => ["ICAP/1.0 200 OK\r\nX-Virus-Name: EICAR\r\n\r\n", false],
        'OPTIONS 200 without Encapsulated'        => ["ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\nPreview: 1024\r\n\r\n", false],
        '206 without Encapsulated'                => ["ICAP/1.0 206 Partial Content\r\n\r\n", false],
        '302 without Encapsulated'                => ["ICAP/1.0 302 Found\r\n\r\n", false],
        '100 without Encapsulated'                => ["ICAP/1.0 100 Continue\r\n\r\n", true],
        '204 without Encapsulated'                => ["ICAP/1.0 204 No Content\r\n\r\n", true],
        '400 without Encapsulated'                => ["ICAP/1.0 400 Bad request\r\n\r\n", true],
        '404 without Encapsulated'                => ["ICAP/1.0 404 ICAP Service not found\r\n\r\n", true],
        '500 without Encapsulated'                => ["ICAP/1.0 500 Server error\r\n\r\n", true],
        '503 without Encapsulated'                => ["ICAP/1.0 503 Service overloaded\r\nConnection: close\r\n\r\n", true],
        '200 with null-body=0'                    => ["ICAP/1.0 200 OK\r\nEncapsulated: null-body=0\r\n\r\n", true],
    ];

    foreach ($cases as $label => [$raw, $accepted]) {
        if ($accepted) {
            expect((new ResponseParser())->parse($raw)->body)->toBe('', $label);
        } else {
            expect(fn () => (new ResponseParser())->parse($raw))
                ->toThrow(IcapMalformedResponseException::class, '', $label);
        }
    }
});

/*
 * A layout that is there must be a legal one, whatever the status: with it the parser
 * knows where the message ends, so a 4xx that carries a layout is read by it.
 */
it('reads the layout of an error response and refuses a broken one', function () {
    $block = "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n";

    $error = (new ResponseParser())->parse("ICAP/1.0 403 Forbidden\r\nEncapsulated: res-hdr=0, null-body=" . strlen($block) . "\r\n\r\n" . $block);
    expect($error->statusCode)->toBe(403)->and($error->body)->toBe('');

    expect(fn () => (new ResponseParser())->parse("ICAP/1.0 403 Forbidden\r\nEncapsulated: res-hdr=0, null-body=10\r\n\r\n" . $block))
        ->toThrow(IcapMalformedResponseException::class);
    expect(fn () => (new ResponseParser())->parse("ICAP/1.0 204 No Content\r\nEncapsulated: nonsense\r\n\r\n"))
        ->toThrow(IcapMalformedResponseException::class);
});

/*
 * M16: a message that repeats what the server sent is a way to the logs and
 * to every handler downstream. The exception says what was wrong with the
 * response, not what it contained: whatever the server sent, the message is
 * the same.
 */
it('keeps the received status line out of the exception message', function () {
    $messages = [
        malformedParserMessage("HTTP/1.1 200 SECRET-STATUS-5c1e\r\n\r\n"),
        malformedParserMessage("ICAP/1.0 SECRET-STATUS-5c1e\r\n\r\n"),
        malformedParserMessage("SECRET-STATUS-5c1e\r\n\r\n"),
        malformedParserMessage("another line, of another length\r\n\r\n"),
    ];

    expect(count(array_unique($messages)))->toBe(1);
    expect($messages[0])->toContain('status line');
    expect(str_contains($messages[0], 'SECRET'))->toBe(false);
});

it('keeps a received header line out of the exception message', function () {
    $messages = [
        malformedParserMessage("ICAP/1.0 200 OK\r\nX-Note: fine\r\nSECRET-HEADER-5c1e without a colon\r\n\r\n"),
        malformedParserMessage("ICAP/1.0 200 OK\r\nSECRET-HEADER-5c1e\r\n\r\n"),
        malformedParserMessage("ICAP/1.0 200 OK\r\nanother line, of another length\r\n\r\n"),
    ];

    expect(count(array_unique($messages)))->toBe(1);
    expect($messages[0])->toContain('header line');
    expect(str_contains($messages[0], 'SECRET'))->toBe(false);
});

it('keeps a refused header name or value out of the exception message', function () {
    $section = "HTTP/1.1 200 OK\r\nX-SECRET-5c1e\x01: v\r\n\r\n";
    $raw = [
        "ICAP/1.0 204 No Content\r\nSECRET-5c1e Bad: v\r\n\r\n",
        "ICAP/1.0 204 No Content\r\n: SECRET-5c1e\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nX-A: SECRET-5c1e\x01\r\n\r\n",
        "ICAP/1.0 204 No Content\r\n SECRET-5c1e: v\r\n\r\n",
        "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($section) . "\r\n\r\n" . $section,
    ];

    foreach ($raw as $i => $wire) {
        expect(str_contains(malformedParserMessage($wire), 'SECRET'))->toBe(false, "case {$i}");
    }
});

it('keeps the value of a folded header and of one with HTAB and obs-text', function () {
    $res = (new ResponseParser())->parse(
        "ICAP/1.0 204 No Content\r\nX-Violations-Found: 1\r\n\tEicar-Test\r\n \tResolution=2\r\nX-Note: \t caf\xE9 \t\r\nX-Empty:\r\n\r\n",
    );

    expect($res->headers['X-Violations-Found'])->toBe(['1 Eicar-Test Resolution=2']);
    expect($res->headers['X-Note'])->toBe(["caf\xE9"]);
    expect($res->headers['X-Empty'])->toBe(['']);
});

/**
 * The message of the IcapMalformedResponseException the parser raises for a response.
 */
function malformedParserMessage(string $raw): string
{
    try {
        (new ResponseParser())->parse($raw);
    } catch (IcapMalformedResponseException $e) {
        return $e->getMessage();
    }

    throw new LogicException('The parser accepted a response that is malformed.');
}

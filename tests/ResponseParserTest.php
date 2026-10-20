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
    // null-body=0 means "no encapsulated body" (RFC 3507 §4.4.1) — any
    // bytes after the ICAP blank line MUST be ignored.
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

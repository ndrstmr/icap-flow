<?php

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

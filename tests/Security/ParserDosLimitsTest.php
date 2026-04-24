<?php

declare(strict_types=1);

use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\ResponseParser;

/**
 * Finding N — parser DoS defences.
 *
 * A malicious ICAP server could emit huge header blocks to try to
 * exhaust the client. The parser must enforce a maximum number of
 * header lines and a maximum single-line length; both limits are
 * configurable so ops can tune them per deployment.
 */

it('refuses a response with too many header lines', function () {
    $parser = new ResponseParser(maxHeaderCount: 3);

    $raw = "ICAP/1.0 200 OK\r\n"
        . "H1: a\r\n"
        . "H2: b\r\n"
        . "H3: c\r\n"
        . "H4: d\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    expect(fn () => $parser->parse($raw))
        ->toThrow(IcapMalformedResponseException::class, 'header');
});

it('refuses a response with a single header line that is too long', function () {
    $parser = new ResponseParser(maxHeaderLineLength: 32);

    $tooLong = str_repeat('a', 100);
    $raw = "ICAP/1.0 200 OK\r\n"
        . "X-Huge: {$tooLong}\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    expect(fn () => $parser->parse($raw))
        ->toThrow(IcapMalformedResponseException::class, 'length');
});

it('accepts a response within both limits', function () {
    $parser = new ResponseParser(maxHeaderCount: 5, maxHeaderLineLength: 128);
    $raw = "ICAP/1.0 204 No Content\r\n"
        . "ISTag: \"abc\"\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";
    $res = $parser->parse($raw);
    expect($res->statusCode)->toBe(204);
});

<?php

declare(strict_types=1);

use Ndrstmr\Icap\ResponseParser;

/**
 * Wire-format assertions for the response parser, exercising real ICAP
 * server output patterns (RFC 3507 §4.3.3, §4.7, §4.10).
 */

it('parses an ICAP 204 "no content" response (null-body)', function () {
    $raw = "ICAP/1.0 204 No Content\r\n"
        . "ISTag: \"1234\"\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res->statusCode)->toBe(204)
        ->and($res->headers['ISTag'][0])->toBe('"1234"')
        ->and($res->headers['Encapsulated'][0])->toBe('null-body=0')
        ->and($res->body)->toBe('');
});

it('parses an ICAP 200 response carrying a chunk-encoded encapsulated HTTP body', function () {
    $httpHead = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "Content-Length: 5\r\n"
        . "\r\n";
    $resBodyOffset = strlen($httpHead);
    $body = "5\r\nhello\r\n0\r\n\r\n";

    $raw = "ICAP/1.0 200 OK\r\n"
        . "ISTag: \"abcd\"\r\n"
        . "Encapsulated: res-hdr=0, res-body={$resBodyOffset}\r\n"
        . "\r\n"
        . $httpHead
        . $body;

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res->statusCode)->toBe(200)
        ->and($res->headers['Encapsulated'][0])->toBe("res-hdr=0, res-body={$resBodyOffset}")
        ->and($res->body)->toBe('hello');
});

it('parses a 100 Continue response cleanly with no encapsulated body', function () {
    $raw = "ICAP/1.0 100 Continue\r\n\r\n";

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res->statusCode)->toBe(100)
        ->and($res->body)->toBe('');
});

it('parses an OPTIONS response with Methods + Preview headers', function () {
    $raw = "ICAP/1.0 200 OK\r\n"
        . "Methods: RESPMOD\r\n"
        . "Service: ICAP/1.0 server\r\n"
        . "ISTag: \"deadbeef\"\r\n"
        . "Max-Connections: 100\r\n"
        . "Options-TTL: 3600\r\n"
        . "Preview: 1024\r\n"
        . "Allow: 204\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res->statusCode)->toBe(200)
        ->and($res->headers['Methods'][0])->toBe('RESPMOD')
        ->and($res->headers['Max-Connections'][0])->toBe('100')
        ->and($res->headers['Options-TTL'][0])->toBe('3600')
        ->and($res->headers['Preview'][0])->toBe('1024')
        ->and($res->headers['Allow'][0])->toBe('204');
});

<?php

declare(strict_types=1);

use Ndrstmr\Icap\DTO\HttpRequest;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\RequestFormatter;

/**
 * Wire-format assertions against RFC 3507 §4.3–§4.5.
 *
 * Every expected byte stream is hand-computed so a passing test literally
 * proves the formatter emits RFC-conformant bytes — no mock short-cuts.
 */

/**
 * @param iterable<string> $chunks
 */
function wire(iterable $chunks): string
{
    $s = '';
    foreach ($chunks as $c) {
        $s .= $c;
    }
    return $s;
}

it('formats an OPTIONS request with null-body=0 (RFC 3507 §4.10)', function () {
    $req = new IcapRequest(
        method: 'OPTIONS',
        uri: 'icap://icap.example.net/service',
        headers: ['User-Agent' => ['icap-flow/2.0']],
    );

    $formatter = new RequestFormatter();

    $expected = "OPTIONS icap://icap.example.net/service ICAP/1.0\r\n"
        . "Host: icap.example.net\r\n"
        . "User-Agent: icap-flow/2.0\r\n"
        . "Connection: close\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n";

    expect(wire($formatter->format($req)))->toBe($expected);
});

it('formats a RESPMOD request with encapsulated HTTP response and chunked body (RFC 3507 §4.4, §4.9)', function () {
    $file = 'hello';
    $httpResponse = new HttpResponse(
        statusCode: 200,
        reasonPhrase: 'OK',
        headers: [
            'Content-Type'   => ['application/octet-stream'],
            'Content-Length' => [(string) strlen($file)],
        ],
        body: $file,
    );

    $req = new IcapRequest(
        method: 'RESPMOD',
        uri: 'icap://icap.example.net/scan',
        headers: ['Host' => ['icap.example.net']],
        encapsulatedResponse: $httpResponse,
    );

    // HTTP response headers block ends with blank CRLF line.
    $httpHeaderBlock = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "Content-Length: 5\r\n"
        . "\r\n";
    $resBodyOffset = strlen($httpHeaderBlock);

    $expected = "RESPMOD icap://icap.example.net/scan ICAP/1.0\r\n"
        . "Host: icap.example.net\r\n"
        . "Connection: close\r\n"
        . "Encapsulated: res-hdr=0, res-body={$resBodyOffset}\r\n"
        . "\r\n"
        . $httpHeaderBlock
        . "5\r\nhello\r\n0\r\n\r\n";

    $formatter = new RequestFormatter();

    expect(wire($formatter->format($req)))->toBe($expected);
});

it('formats a REQMOD with encapsulated HTTP request and chunked body (RFC 3507 §4.8)', function () {
    $postBody = 'hi!';
    $httpRequest = new HttpRequest(
        method: 'POST',
        requestTarget: '/upload',
        headers: [
            'Host'           => ['target.example.com'],
            'Content-Length' => [(string) strlen($postBody)],
        ],
        body: $postBody,
    );

    $req = new IcapRequest(
        method: 'REQMOD',
        uri: 'icap://icap.example.net/scan',
        headers: ['Host' => ['icap.example.net']],
        encapsulatedRequest: $httpRequest,
    );

    $httpHeaderBlock = "POST /upload HTTP/1.1\r\n"
        . "Host: target.example.com\r\n"
        . "Content-Length: 3\r\n"
        . "\r\n";
    $reqBodyOffset = strlen($httpHeaderBlock);

    $expected = "REQMOD icap://icap.example.net/scan ICAP/1.0\r\n"
        . "Host: icap.example.net\r\n"
        . "Connection: close\r\n"
        . "Encapsulated: req-hdr=0, req-body={$reqBodyOffset}\r\n"
        . "\r\n"
        . $httpHeaderBlock
        . "3\r\nhi!\r\n0\r\n\r\n";

    $formatter = new RequestFormatter();

    expect(wire($formatter->format($req)))->toBe($expected);
});

it('emits 0; ieof\\r\\n\\r\\n when the preview body is the complete payload (RFC 3507 §4.5)', function () {
    $file = 'hello';
    $httpResponse = new HttpResponse(
        statusCode: 200,
        headers: [
            'Content-Type'   => ['application/octet-stream'],
            'Content-Length' => [(string) strlen($file)],
        ],
        body: $file,
    );

    $req = new IcapRequest(
        method: 'RESPMOD',
        uri: 'icap://icap.example.net/scan',
        headers: [
            'Host'    => ['icap.example.net'],
            'Preview' => [(string) strlen($file)],
        ],
        encapsulatedResponse: $httpResponse,
        previewIsComplete: true,
    );

    $httpHeaderBlock = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "Content-Length: 5\r\n"
        . "\r\n";
    $resBodyOffset = strlen($httpHeaderBlock);

    $expected = "RESPMOD icap://icap.example.net/scan ICAP/1.0\r\n"
        . "Host: icap.example.net\r\n"
        . "Preview: 5\r\n"
        . "Connection: close\r\n"
        . "Encapsulated: res-hdr=0, res-body={$resBodyOffset}\r\n"
        . "\r\n"
        . $httpHeaderBlock
        . "5\r\nhello\r\n0; ieof\r\n\r\n";

    $formatter = new RequestFormatter();

    expect(wire($formatter->format($req)))->toBe($expected);
});

it('chunks a stream-resource body without buffering it as a single string', function () {
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to open temp stream');
    }
    fwrite($stream, 'ABCDEFGHIJ');
    rewind($stream);

    $httpResponse = new HttpResponse(
        statusCode: 200,
        headers: [
            'Content-Type'   => ['application/octet-stream'],
            'Content-Length' => ['10'],
        ],
        body: $stream,
    );

    $req = new IcapRequest(
        method: 'RESPMOD',
        uri: 'icap://icap.example.net/scan',
        encapsulatedResponse: $httpResponse,
    );

    $formatter = new RequestFormatter();
    // Assert the body chunk exists and the end-of-body terminator is present.
    $result = wire($formatter->format($req));

    expect($result)
        ->toContain("a\r\nABCDEFGHIJ\r\n0\r\n\r\n")
        ->toContain("Encapsulated: res-hdr=0, res-body=");
});

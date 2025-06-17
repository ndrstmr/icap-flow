<?php

use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\RequestFormatter;

it('formats a basic OPTIONS request', function () {
    $req = new IcapRequest('OPTIONS', 'icap://icap.example.net/service');
    $req = $req->withHeader('User-Agent', 'icap-flow');

    $formatter = new RequestFormatter();

    $expected = "OPTIONS icap://icap.example.net/service ICAP/1.0\r\n" .
        "User-Agent: icap-flow\r\n" .
        "Host: icap.example.net\r\n" .
        "Encapsulated: null-body=0\r\n" .
        "\r\n";

    expect($formatter->format($req))->toBe($expected);
});

it('formats a request with stream body using chunked encoding', function () {
    $stream = fopen('php://temp', 'r+');
    expect($stream)->not->toBeFalse();

    fwrite($stream, 'hello world');
    rewind($stream);

    $req = new IcapRequest('RESPMOD', 'icap://icap.example.net/service', [], $stream);

    $formatter = new RequestFormatter();

    $expectedStart = "RESPMOD icap://icap.example.net/service ICAP/1.0\r\n" .
        "Host: icap.example.net\r\n" .
        "Encapsulated: null-body=0\r\n" .
        "\r\n";
    $result = $formatter->format($req);

    expect($result)->toStartWith($expectedStart)
        ->and($result)->toContain("b\r\nhello world\r\n0\r\n\r\n");
});

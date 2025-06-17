<?php

use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\DTO\IcapResponse;

it('parses a basic ICAP response', function () {
    $raw = "ICAP/1.0 200 OK\r\n" .
        "Encapsulated: null-body=0\r\n" .
        "\r\n" .
        "b\r\nhello world\r\n0\r\n\r\n";

    $parser = new ResponseParser();
    $res = $parser->parse($raw);

    expect($res)->toBeInstanceOf(IcapResponse::class)
        ->and($res->statusCode)->toBe(200)
        ->and($res->headers['Encapsulated'])->toEqual(['null-body=0'])
        ->and($res->body)->toBe('hello world');
});

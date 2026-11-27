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

use Mockery;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpRequest;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use PHPUnit\Framework\Assert;

/*
 * Optional fixed encapsulated HTTP request head (`req-hdr`, RFC 3507 §4.9.1) on IcapClient
 * for all scanFile*() calls of that client.
 */

afterEach(function (): void {
    FileWorkers::removeFiles();
});

function requestHeadClient(TransportInterface $transport, ?HttpRequest $head = null, ?Config $config = null): IcapClient
{
    return new IcapClient(
        $config ?? new Config('icap.example'),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
        encapsulatedRequest: $head,
    );
}

function expectedRespmodHead(int $contentLength, string $extraIcapHeaders = '', string $bodyTag = 'res-body'): string
{
    $reqBlock = "GET /upload HTTP/1.1\r\n"
        . "Host: localhost\r\n"
        . "\r\n";
    $resBlock = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "Content-Length: {$contentLength}\r\n"
        . "\r\n";
    $resHdrOffset = strlen($reqBlock);
    $bodyOffset = $resHdrOffset + strlen($resBlock);

    return "RESPMOD icap://icap.example/avscan ICAP/1.0\r\n"
        . "Host: icap.example\r\n"
        . $extraIcapHeaders
        . "Encapsulated: req-hdr=0, res-hdr={$resHdrOffset}, {$bodyTag}={$bodyOffset}\r\n"
        . "\r\n"
        . $reqBlock
        . $resBlock;
}

it('includes the fixed encapsulated request head in scanFile() with exact wire bytes', function () {
    $transport = new ScriptedTransport();
    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $client = requestHeadClient($transport, $head);
    $path = FileWorkers::file('hello');

    $client->scanFile('/avscan', $path, ['Allow' => '204'])->await();
    // The fixed head applies to every scan of the client, including through the wrappers.
    (new SynchronousIcapClient($client))->scanFile('/avscan', $path, ['Allow' => '204']);
    (new RetryingIcapClient($client))->scanFile('/avscan', $path, ['Allow' => '204'])->await();

    $expected = expectedRespmodHead(5, "Allow: 204\r\n") . "5\r\nhello\r\n0\r\n\r\n";

    expect($transport->requests)->toBe([$expected, $expected, $expected]);
});

it('includes the fixed request head in a partial preview and sends only the body remainder after 100 Continue', function () {
    $transport = new ScriptedTransport([
        "ICAP/1.0 100 Continue\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
        "ICAP/1.0 100 Continue\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
    ]);
    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $client = requestHeadClient($transport, $head);
    $path = FileWorkers::file('abcdef');

    $client->scanFileWithPreview('/avscan', $path, 3)->await();
    $client->scanFileWithPreview('/avscan', $path, 0)->await();

    expect($transport->requests)->toBe([
        expectedRespmodHead(6, "Preview: 3\r\nAllow: 204\r\n") . "3\r\nabc\r\n0\r\n\r\n",
        "3\r\ndef\r\n0\r\n\r\n",
        expectedRespmodHead(6, "Preview: 0\r\nAllow: 204\r\n") . "0\r\n\r\n",
        "6\r\nabcdef\r\n0\r\n\r\n",
    ]);
});

it('includes the fixed request head in a complete preview ending in ieof', function () {
    $transport = new ScriptedTransport();
    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $client = requestHeadClient($transport, $head);
    $path = FileWorkers::file('hello');

    $client->scanFileWithPreview('/avscan', $path, 1024)->await();

    expect($transport->requests)->toBe([
        expectedRespmodHead(5, "Preview: 1024\r\nAllow: 204\r\n") . "5\r\nhello\r\n0; ieof\r\n\r\n",
    ]);
});

it('includes the fixed request head when scanning a zero-length file with and without preview', function () {
    $transport = new ScriptedTransport();
    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $client = requestHeadClient($transport, $head);
    $path = FileWorkers::file('');

    $client->scanFile('/avscan', $path)->await();
    $client->scanFileWithPreview('/avscan', $path, 1024)->await();
    $client->scanFileWithPreview('/avscan', $path, 0)->await();

    expect($transport->requests)->toBe([
        expectedRespmodHead(0) . "0\r\n\r\n",
        expectedRespmodHead(0, "Preview: 1024\r\nAllow: 204\r\n", 'null-body'),
        expectedRespmodHead(0, "Preview: 0\r\nAllow: 204\r\n", 'null-body'),
    ]);
});

it('leaves scan bytes unchanged without encapsulatedRequest and leaves request() and options() untouched with one', function () {
    $without = new ScriptedTransport();
    $plainClient = requestHeadClient($without);
    $path = FileWorkers::file('hello');

    $plainClient->scanFile('/avscan', $path)->await();

    $resBlock = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/octet-stream\r\n"
        . "Content-Length: 5\r\n"
        . "\r\n";
    expect($without->requests)->toBe([
        "RESPMOD icap://icap.example/avscan ICAP/1.0\r\n"
        . "Host: icap.example\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($resBlock) . "\r\n"
        . "\r\n"
        . $resBlock
        . "5\r\nhello\r\n0\r\n\r\n",
    ]);

    // With encapsulatedRequest configured, options() and raw request() do not inherit it.
    $with = new ScriptedTransport([
        "ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\nEncapsulated: null-body=0\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
    ]);
    $headClient = requestHeadClient($with, new HttpRequest('GET', '/upload', ['Host' => ['localhost']]));
    $headClient->options('/avscan')->await();
    $headClient->request(new IcapRequest(
        method: 'RESPMOD',
        uri: 'icap://icap.example/avscan',
        encapsulatedResponse: new HttpResponse(200, headers: ['Content-Type' => ['application/octet-stream'], 'Content-Length' => ['5']], body: 'hello'),
    ))->await();

    expect($with->requests[0])->toContain("Encapsulated: null-body=0\r\n")
        ->and($with->requests[0])->not->toContain('req-hdr=')
        ->and($with->requests[1])->toContain('Encapsulated: res-hdr=0, res-body=')
        ->and($with->requests[1])->not->toContain('req-hdr=');
});

it('includes the fixed request head in the discarding scan helpers over real sockets', function () {
    $server = IcapTestServer::start(static fn (array $request): array => [
        $request['stage'] === 'preview' ? IcapTestServer::CONTINUE : IcapTestServer::CLEAN,
    ]);
    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $path = FileWorkers::file(str_repeat('A', 4096));

    try {
        $client = requestHeadClient(new AsyncAmpTransport(), $head, new Config('127.0.0.1', $server->port));

        $single = $client->scanFileDiscardingBody('/avscan', $path, 1 << 20)->await();
        $preview = $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await();

        expect($single->isInfected())->toBeFalse()
            ->and($preview->isInfected())->toBeFalse()
            ->and(array_column($server->requests, 'stage'))->toBe(['single', 'preview', 'rest'])
            ->and($server->requests[0]['layout'])->toHaveKeys(['req-hdr', 'res-hdr', 'res-body'])
            ->and($server->requests[0]['layout']['req-hdr'])->toBe(0)
            ->and($server->requests[0]['bodyBytes'])->toBe(4096)
            ->and($server->requests[1]['layout'])->toHaveKeys(['req-hdr', 'res-hdr', 'res-body'])
            ->and($server->requests[1]['layout']['req-hdr'])->toBe(0)
            ->and($server->requests[2]['bodyBytes'])->toBe(4096);
    } finally {
        $server->stop();
    }
});

it('rejects an invalid encapsulatedRequest in the constructor before anything is sent', function () {
    $stream = fopen('php://memory', 'r+');
    Assert::assertIsResource($stream);

    // Callers without static analysis can pass untyped header arrays.
    $withHeaders = static fn (array $headers): HttpRequest => new HttpRequest('GET', '/upload', $headers);

    $cases = [
        'non-empty string body'      => new HttpRequest('GET', '/upload', ['Host' => ['localhost']], body: 'payload'),
        'empty string body'          => new HttpRequest('GET', '/upload', ['Host' => ['localhost']], body: ''),
        'stream body'                => new HttpRequest('GET', '/upload', ['Host' => ['localhost']], body: $stream),
        'empty method'               => new HttpRequest('', '/upload'),
        'method with space'          => new HttpRequest('GET /admin', '/upload'),
        'method with CRLF'           => new HttpRequest("GET\r\n", '/upload'),
        'empty request target'       => new HttpRequest('GET', ''),
        'request target with space'  => new HttpRequest('GET', '/my file.txt'),
        'request target with CRLF'   => new HttpRequest('GET', "/upload\r\nX-Injected: yes"),
        'request target with DEL'    => new HttpRequest('GET', "/upload\x7F"),
        'invalid HTTP version'       => new HttpRequest('GET', '/upload', httpVersion: 'HTTP/2'),
        'HTTP version with CRLF'     => new HttpRequest('GET', '/upload', httpVersion: "HTTP/1.1\r\nX-Injected: yes"),
        'header name with space'     => $withHeaders(['X Bad' => ['1']]),
        'header name with separator' => $withHeaders(['X/Bad' => ['1']]),
        'header name ending in LF'   => $withHeaders(["X-Bad\n" => ['1']]),
        'header list instead of map' => $withHeaders(['Host: localhost']),
        'header value with CR'       => $withHeaders(['Host' => ["localhost\rX-Injected: 1"]]),
        'header value with LF'       => $withHeaders(['Host' => ["localhost\nX-Injected: 1"]]),
        'header value with CRLF'     => $withHeaders(['Host' => ["localhost\r\nX-Injected: 1"]]),
        'header value with NUL'      => $withHeaders(['Host' => ["local\x00host"]]),
        'header value with SOH'      => $withHeaders(['Host' => ["a\x01b"]]),
        'header value with DEL'      => $withHeaders(['Host' => ["a\x7Fb"]]),
        'header value of type int'   => $withHeaders(['Host' => [42]]),
    ];

    try {
        foreach ($cases as $label => $invalid) {
            /** @var SessionAwareTransport&\Mockery\MockInterface $transport */
            $transport = Mockery::mock(SessionAwareTransport::class);
            $transport->shouldNotReceive('request');
            $transport->shouldNotReceive('openSession');

            expect(static fn () => new IcapClient(
                new Config('icap.example'),
                $transport,
                new RequestFormatter(),
                new ResponseParser(),
                encapsulatedRequest: $invalid,
            ))->toThrow(\InvalidArgumentException::class, '', $label);
        }
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        Mockery::close();
    }
});

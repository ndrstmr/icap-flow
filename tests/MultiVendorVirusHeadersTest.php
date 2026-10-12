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

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

/**
 * M3.5 — multi-vendor virus-name detection.
 *
 * Different ICAP server vendors report infections via different
 * headers. Config must accept an ordered list of candidate headers
 * and the client picks the first one the server actually sent.
 *
 *   - Clam AV / c-icap:   X-Virus-Name
 *   - Trend Micro:        X-Violations-Found
 *   - ISS Proventia:      X-Infection-Found
 *   - Symantec:           X-Virus-ID
 */

/**
 * @param list<string>            $virusHeaders
 * @param array<string, string[]> $headers
 */
function buildClientWithVirusHeaders(array $virusHeaders, array $headers): IcapClient
{
    $config = (new Config('icap.example'))->withVirusFoundHeaders($virusHeaders);
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(200, $headers));

    return new IcapClient($config, $transport, $formatter, $parser);
}

it('backwards-compat: Config defaults to X-Virus-Name only', function () {
    $cfg = new Config('h');
    expect($cfg->getVirusFoundHeaders())->toBe(['X-Virus-Name'])
        ->and($cfg->getVirusFoundHeader())->toBe('X-Virus-Name');
});

it('detects a virus via the first vendor header that is present', function () {
    $client = buildClientWithVirusHeaders(
        ['X-Virus-Name', 'X-Infection-Found', 'X-Violations-Found'],
        ['X-Infection-Found' => ['Type=0; Resolution=2; Threat=EICAR;']],
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->request(new IcapRequest('RESPMOD', 'icap://h/svc'))->await();
        expect($res->isInfected())->toBeTrue()
            ->and($res->getVirusName())->toBe('Type=0; Resolution=2; Threat=EICAR;');
    });

    m::close();
});

it('returns clean when none of the configured virus headers is present', function () {
    $client = buildClientWithVirusHeaders(
        ['X-Virus-Name', 'X-Infection-Found'],
        ['ISTag' => ['"1234"']],
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->request(new IcapRequest('RESPMOD', 'icap://h/svc'))->await();
        expect($res->isInfected())->toBeFalse();
    });

    m::close();
});

it('picks the first header in the configured order when multiple are present', function () {
    $client = buildClientWithVirusHeaders(
        ['X-Virus-Name', 'X-Infection-Found'],
        [
            'X-Virus-Name'      => ['Eicar-Test'],
            'X-Infection-Found' => ['Type=0; Threat=OtherName;'],
        ],
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->request(new IcapRequest('RESPMOD', 'icap://h/svc'))->await();
        expect($res->getVirusName())->toBe('Eicar-Test');
    });

    m::close();
});

it('rejects an empty virus-header list', function () {
    expect(fn () => (new Config('h'))->withVirusFoundHeaders([]))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * B01 — header field names are case-insensitive (RFC 3507 §4.3 via
 * RFC 7230 §3.2). The raw responses below run through the real parser;
 * every spelling of the virus header must yield the same verdict.
 */
function rawResponseWithVirusHeader(string $spelling): string
{
    $httpHead = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";

    return "ICAP/1.0 200 OK\r\n"
        . "ISTag: \"b01\"\r\n"
        . "{$spelling}: Eicar-Test-Signature\r\n"
        . 'Encapsulated: res-hdr=0, res-body=' . strlen($httpHead) . "\r\n"
        . "\r\n"
        . $httpHead
        . "0\r\n\r\n";
}

/**
 * @param list<string> $virusHeaders
 */
function buildClientForRawResponse(string $raw, array $virusHeaders): IcapClient
{
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete($raw));

    return new IcapClient(
        (new Config('icap.example'))->withVirusFoundHeaders($virusHeaders),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
    );
}

/**
 * Pairs of configured header name and the spelling the server sends.
 *
 * @return list<array{string, string}>
 */
function virusHeaderSpellings(): array
{
    return [
        ['X-Virus-Name', 'x-virus-name'],
        ['X-Virus-Name', 'X-VIRUS-NAME'],
        ['x-virus-name', 'X-Virus-Name'],
    ];
}

it('reports infected for every spelling of the virus header in a raw response', function () {
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () {
        foreach (virusHeaderSpellings() as [$configured, $sent]) {
            $res = buildClientForRawResponse(rawResponseWithVirusHeader($sent), [$configured])
                ->request(new IcapRequest('RESPMOD', 'icap://icap.example/avscan'))
                ->await();
            expect($res->isInfected())->toBeTrue("{$sent} must match {$configured}")
                ->and($res->getVirusName())->toBe('Eicar-Test-Signature');
        }
    });

    m::close();
});

it('reports infected in the preview flow for every spelling of the virus header', function () {
    $file = tempnam(sys_get_temp_dir(), 'icap_b01_');
    if ($file === false) {
        throw new RuntimeException('Failed to create temp file');
    }
    file_put_contents($file, str_repeat('X', 64));

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($file) {
            foreach (virusHeaderSpellings() as [$configured, $sent]) {
                // An 8-byte preview of the 64-byte file leaves the body
                // unfinished, so the preview strategy judges the 200.
                $res = buildClientForRawResponse(rawResponseWithVirusHeader($sent), [$configured])
                    ->scanFileWithPreview('/avscan', $file, 8)
                    ->await();
                expect($res->isInfected())->toBeTrue("{$sent} must match {$configured}")
                    ->and($res->getVirusName())->toBe('Eicar-Test-Signature');
            }
        });
    } finally {
        @unlink($file);
    }

    m::close();
});

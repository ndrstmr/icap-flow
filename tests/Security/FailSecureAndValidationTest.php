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
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

/**
 * Finding G — Fail-Secure semantics.
 *
 * RFC 3507 §4.3.3: status 100 Continue is only meaningful inside a
 * preview exchange. A bare 100 handed to interpretResponse() by any
 * other code path is a protocol error, NOT a "clean" scan result.
 * Returning a clean ScanResult would be a fail-open security bug.
 */

/**
 * @return array{Config, RequestFormatterInterface&\Mockery\MockInterface, TransportInterface&\Mockery\MockInterface, ResponseParserInterface&\Mockery\MockInterface, IcapClient}
 */
function failSecureClient(IcapResponse $canned): array
{
    $config = new Config('icap.example');
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
    $p->andReturn($canned);

    return [$config, $formatter, $transport, $parser, new IcapClient($config, $transport, $formatter, $parser)];
}

it('fails secure: a bare 100 Continue outside a preview is a protocol error, not a clean scan', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(100));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())
            ->toThrow(IcapProtocolException::class);
    });

    m::close();
});

it('maps 4xx responses to IcapClientException with the real status code', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(400));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        try {
            $client->options('/svc')->await();
            expect(false)->toBeTrue('expected IcapClientException');
        } catch (IcapClientException $e) {
            expect($e->getCode())->toBe(400);
        }
    });

    m::close();
});

it('maps 5xx responses to IcapServerException with the real status code', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(503));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        try {
            $client->options('/svc')->await();
            expect(false)->toBeTrue('expected IcapServerException');
        } catch (IcapServerException $e) {
            expect($e->getCode())->toBe(503);
        }
    });

    m::close();
});

it('treats a 200 response with no virus header as clean', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(200));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->options('/svc')->await();
        expect($res->isInfected())->toBeFalse();
    });

    m::close();
});

it('treats a 200 response carrying a virus header as infected', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(200, [
        'X-Virus-Name' => ['Eicar-Test-Signature'],
    ]));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->options('/svc')->await();
        expect($res->isInfected())->toBeTrue()
            ->and($res->getVirusName())->toBe('Eicar-Test-Signature');
    });

    m::close();
});

/**
 * Finding H — CRLF / header-injection validation on $service.
 *
 * RFC 3507 §7.3 + general HTTP header-injection hygiene: the service
 * path is embedded into the request URI and the Host-derived headers.
 * CR/LF/NUL/space anywhere in $service MUST be rejected before any
 * bytes are emitted onto the socket.
 */

it('rejects \\r in the service path before hitting the wire', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->options("/svc\r\nX-Injected: 1"))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects \\n in the service path', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->options("/svc\nX-Injected: 1"))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects NUL in the service path', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->options("/svc\0/\0"))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects whitespace in the service path', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->options('/svc has space'))
        ->toThrow(InvalidArgumentException::class);
});

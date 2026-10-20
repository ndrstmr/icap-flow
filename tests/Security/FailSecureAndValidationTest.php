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

use Amp\Socket;
use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
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

/**
 * N02 — the answer to OPTIONS is a 200 (RFC 3507 §4.10.2). A 204 or 206
 * belongs to the adaptation of a message, and a 1xx, 3xx or 6xx is no answer
 * to OPTIONS at all: none of them tells the client what the service can do,
 * so none may come back as a capability description. 4xx and 5xx keep their
 * types.
 */
it('accepts only a 200 as the answer to OPTIONS and types every other status', function () {
    // status => the exception options() raises, null when the response comes back as it is
    $cases = [
        200 => null,
        100 => IcapProtocolException::class,
        101 => IcapProtocolException::class,
        199 => IcapProtocolException::class,
        201 => IcapProtocolException::class,
        204 => IcapProtocolException::class,
        206 => IcapProtocolException::class,
        299 => IcapProtocolException::class,
        302 => IcapProtocolException::class,
        399 => IcapProtocolException::class,
        400 => IcapClientException::class,
        404 => IcapClientException::class,
        499 => IcapClientException::class,
        500 => IcapServerException::class,
        503 => IcapServerException::class,
        599 => IcapServerException::class,
        600 => IcapProtocolException::class,
        999 => IcapProtocolException::class,
    ];

    foreach ($cases as $status => $exception) {
        $canned = new IcapResponse($status, ['Options-TTL' => ['60'], 'Preview' => ['4096']]);
        [, , , , $client] = failSecureClient($canned);

        $thrown = null;
        $returned = null;
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, &$thrown, &$returned) {
            try {
                $returned = $client->options('/svc')->await();
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        // The exact class: a subclass of IcapProtocolException would be another answer.
        expect($thrown === null ? null : $thrown::class)->toBe($exception, "status {$status}");
        if ($exception === null) {
            expect($returned)->toBe($canned, "status {$status}");
        } else {
            expect($returned)->toBeNull("status {$status}")
                ->and($thrown?->getCode())->toBe($status, "status {$status}");
        }
    }

    m::close();
});

it('treats a 200 response with no virus header as clean', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(200));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $res = $client->request(new IcapRequest('RESPMOD', 'icap://h/svc'))->await();
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
        $res = $client->request(new IcapRequest('RESPMOD', 'icap://h/svc'))->await();
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

/**
 * N04 — the service is an absolute path with an optional query, the host a
 * host name or an IP address. Anything else would move the URI authority,
 * path or query, or add a fragment.
 */
it('rejects service paths and hosts that would move the URI authority, path or query', function () {
    [, , , , $client] = failSecureClient(new IcapResponse(200));

    $services = [
        'no leading slash'      => '.evil.example/scan',
        'user info'             => '@evil.example/scan',
        'fragment'              => '/scan#part',
        'broken percent escape' => '/scan%zz',
    ];
    // "/" or "?" in the host would turn the service into another path or
    // into a query, in the ICAP URI and in the socket address alike. Since N01
    // no Config holds such a host, so neither address can be built from it.
    $hosts = [
        'host with a path'  => 'scanner.example/other',
        'host with a query' => 'scanner.example?query=',
    ];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $services) {
        foreach ($services as $case => $service) {
            expect(fn () => $client->options($service)->await())
                ->toThrow(InvalidArgumentException::class, '', $case);
        }
    });
    foreach ($hosts as $case => $host) {
        expect(fn () => new Config($host))
            ->toThrow(InvalidArgumentException::class, '', $case);
    }

    m::close();
});

it('builds the service URI with a query and a bracketed IPv6 host', function () {
    $wire = '';
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->withArgs(
        /** @param iterable<string> $chunks */
        function (Config $config, iterable $chunks) use (&$wire): bool {
            foreach ($chunks as $chunk) {
                $wire .= $chunk;
            }
            return true;
        },
    );
    $t->andReturn(\Amp\Future::complete("ICAP/1.0 200 OK\r\nEncapsulated: null-body=0\r\n\r\n"));

    $client = new IcapClient(new Config('::1', 1345), $transport, new RequestFormatter(), new ResponseParser());

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/scan?mode=full&path=%2Fdata')->await();
    });

    expect($wire)->toBe(
        "OPTIONS icap://[::1]:1345/scan?mode=full&path=%2Fdata ICAP/1.0\r\n"
        . "Host: [::1]:1345\r\n"
        . "Encapsulated: null-body=0\r\n"
        . "\r\n",
    );

    m::close();
});

/**
 * B04 — request() takes caller-built DTOs as they are. Invalid metadata
 * must fail before the transport writes anything, not after the ICAP
 * head is already on the socket.
 */
it('writes no byte to the socket when request() carries invalid metadata', function () {
    $requests = [
        'ICAP header' => new IcapRequest(
            'RESPMOD',
            'icap://icap.example/scan',
            ['X-User' => ["safe\r\nX-Injected: yes"]],
            encapsulatedResponse: new HttpResponse(200, body: 'data'),
        ),
        'HTTP header' => new IcapRequest(
            'RESPMOD',
            'icap://icap.example/scan',
            encapsulatedResponse: new HttpResponse(200, headers: ['X-Http' => ["safe\r\nX-Http-Injected: yes"]], body: 'data'),
        ),
    ];

    foreach ($requests as $case => $request) {
        [$server, $socket] = Socket\createSocketPair();
        $pool = new AmpConnectionPool(connector: fn () => $socket);
        $client = new IcapClient(
            new Config('icap.example', streamTimeout: 0.2),
            new AsyncAmpTransport($pool),
            new RequestFormatter(),
            new ResponseParser(),
        );

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $request, $server, $socket, $case) {
                expect(fn () => $client->request($request)->await())
                    ->toThrow(InvalidArgumentException::class, '', $case);

                // With the client end closed, the server end yields whatever
                // reached the wire and then the end of the stream.
                $socket->close();
                $received = '';
                while (($chunk = $server->read()) !== null) {
                    $received .= $chunk;
                }
                expect($received)->toBe('', $case);
            });
        } finally {
            $socket->close();
            $server->close();
            $pool->close();
        }
    }
});

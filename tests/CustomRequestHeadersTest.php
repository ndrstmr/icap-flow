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
 * M3.6 — custom ICAP request headers.
 *
 * Real deployments need to pass `X-Client-IP`, `X-Authenticated-User`,
 * `X-Server-IP` etc. through to the ICAP server for policy decisions
 * (RFC 3507 §4.3.2 + de-facto vendor additions). Both scanFile and
 * scanFileWithPreview must accept an optional $extraHeaders map.
 */

it('scanFile merges caller-supplied headers including Allow into the outgoing request and drops a caller Preview', function () {
    $captured = null;

    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return true;
    });
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
            $client->scanFile('/svc', $tmp, [
                'X-Client-IP'          => '203.0.113.5',
                'X-Authenticated-User' => 'dGVzdA==',
                // scanFile() sends the full request without a preview
                // handshake: a Preview would announce one that never
                // happens, so it goes in no spelling. Allow: 204 stays.
                'Preview'              => '1024',
                'preview'              => '0',
                'Allow'                => '204',
            ])->await();

            assert($captured instanceof IcapRequest);
            expect($captured->headers)->toBe([
                'X-Client-IP'          => ['203.0.113.5'],
                'X-Authenticated-User' => ['dGVzdA=='],
                'Allow'                => ['204'],
            ]);
        });
    } finally {
        @unlink($tmp);
    }

    m::close();
});

it('scanFileWithPreview merges caller-supplied headers without overriding Preview/Allow, before and after 100 Continue', function () {
    $wires = [];

    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->twice();
    $t->withArgs(
        /** @param iterable<string> $chunks */
        function (Config $config, iterable $chunks) use (&$wires): bool {
            $wire = '';
            foreach ($chunks as $chunk) {
                $wire .= $chunk;
            }
            $wires[] = $wire;
            return true;
        },
    );
    // Without sessions the client sends the preview and, after
    // 100 Continue, the whole file in a second request.
    $t->andReturn(
        \Amp\Future::complete("ICAP/1.0 100 Continue\r\n\r\n"),
        \Amp\Future::complete("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n"),
    );

    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp) {
            $client->scanFileWithPreview('/svc', $tmp, 3, [
                'X-Client-IP' => '203.0.113.99',
                // Caller tries to override Preview — library-managed
                // headers MUST win to keep the protocol state sound.
                'Preview'     => 'ignored',
                'Allow'       => 'also-ignored',
                // In any spelling, so no second Preview/Allow line goes out.
                'preview'     => '99999',
                'ALLOW'       => 'x',
            ])->await();
        });

        $httpHead = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 6\r\n"
            . "\r\n";
        $encapsulated = 'Encapsulated: res-hdr=0, res-body=' . strlen($httpHead) . "\r\n";

        expect($wires)->toBe([
            "RESPMOD icap://icap.example/svc ICAP/1.0\r\n"
            . "Host: icap.example\r\n"
            . "X-Client-IP: 203.0.113.99\r\n"
            . "Preview: 3\r\n"
            . "Allow: 204\r\n"
            . $encapsulated
            . "\r\n"
            . $httpHead
            . "3\r\nabc\r\n0\r\n\r\n",
            // The full request is no preview: the caller's Preview/Allow
            // stay out of it as well, the other caller headers go along.
            "RESPMOD icap://icap.example/svc ICAP/1.0\r\n"
            . "Host: icap.example\r\n"
            . "X-Client-IP: 203.0.113.99\r\n"
            . $encapsulated
            . "\r\n"
            . $httpHead
            . "6\r\nabcdef\r\n0\r\n\r\n",
        ]);
    } finally {
        @unlink($tmp);
    }

    m::close();
});

it('rejects header names that contain CR/LF (injection)', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->scanFile('/svc', __FILE__, ["X-Bad\r\nInjected" => 'value']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects header values that contain CR/LF (injection)', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->scanFile('/svc', __FILE__, ['X-Bad' => "value\r\nInjected: 1"]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects header fields the wire format cannot carry before the request starts', function () {
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    $transport->shouldNotReceive('request');
    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());

    // Callers without static analysis can pass any PHP type, so the
    // header maps go in untyped.
    $scan = fn (array $headers) => $client->scanFile('/svc', __FILE__, $headers)->await();
    $cases = [
        'name ending in LF'     => ["X-Bad\n" => 'value'],
        'value with SOH'        => ['X-Bad' => "a\x01b"],
        'value with DEL'        => ['X-Bad' => "a\x7Fb"],
        'value of type int'     => ['X-Bad' => 5],
        'list instead of a map' => ['X-Bad: value'],
    ];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($scan, $cases) {
        foreach ($cases as $case => $headers) {
            expect(fn () => $scan($headers))->toThrow(InvalidArgumentException::class, '', $case);
        }
    });

    m::close();
});

/**
 * v2.2-S — RFC 7230 §3.2.6 strict header-name validation.
 *
 * tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" /
 *         "." / "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
 *
 * Anything outside this set must be rejected. The previous regex only
 * blocked control characters and the colon; separator tokens like
 * parentheses, brackets, slash, at-sign etc. slipped through.
 */

it('rejects header names containing RFC 7230 separator tokens', function () {
    $client = IcapClient::forServer('icap.example');

    // Each of these characters is a valid ASCII printable but NOT a
    // valid tchar — they must all be rejected.
    $separators = ['(', ')', '<', '>', '@', ',', ';', '\\', '"', '/', '[', ']', '?', '=', '{', '}', ' ', "\t"];

    foreach ($separators as $sep) {
        expect(fn () => $client->scanFile('/svc', __FILE__, ["X-Bad{$sep}Name" => 'value']))
            ->toThrow(InvalidArgumentException::class, '', "Separator '{$sep}' was not rejected in header name");
    }
});

it('accepts valid tchar-only header names per RFC 7230', function () {
    $captured = null;

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
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload');

    // All valid tchar characters in a header name.
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFile('/svc', $tmp, [
            "X-Valid!#\$%&'*+-.^_`|~09azAZ" => 'ok',
        ])->await();
    });

    // If we got here without exception, the name was accepted.
    expect(true)->toBeTrue();

    unlink($tmp);
    m::close();
});

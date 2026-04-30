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
use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\TransportSession;

uses(AsyncTestCase::class);

/**
 * v2.2-K — OPTIONS-driven Preview-Size: when no explicit $previewSize is
 * passed to scanFileWithPreview(), the client queries the OPTIONS cache
 * for the server's advertised Preview header and uses that value. Falls
 * back to 1024 when no cache is configured or no Preview header exists.
 */

/**
 * Build an IcapClient with a SessionAwareTransport mock. The formatter
 * spy captures the IcapRequest so tests can inspect the Preview header.
 *
 * @param IcapRequest|null $capturedRequest receives the RESPMOD request via reference
 * @return array{IcapClient, InMemoryOptionsCache}
 */
function makePreviewSizeClient(?IcapRequest &$capturedRequest, ?InMemoryOptionsCache $cache = null): array
{
    $config = new Config('icap.example');
    $cache ??= new InMemoryOptionsCache();

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var SessionAwareTransport&\Mockery\MockInterface $transport */
    $transport = m::mock(SessionAwareTransport::class);
    /** @var TransportSession&\Mockery\MockInterface $session */
    $session = m::mock(TransportSession::class);

    // Capture the IcapRequest passed to format() so the test can
    // inspect the Preview header value chosen by the client.
    /** @var \Mockery\Expectation $fe */
    $fe = $formatter->shouldReceive('format');
    $fe->andReturnUsing(function (IcapRequest $req) use (&$capturedRequest): array {
        $capturedRequest = $req;
        return ['FORMATTED'];
    });

    // The session mock drives a simple "204 No Content" (clean) flow:
    // preview is complete for our tiny test file, so the client reads
    // exactly one response and then releases.
    /** @var \Mockery\Expectation $sw */
    $sw = $session->shouldReceive('write');
    $sw->andReturnNull();
    /** @var \Mockery\Expectation $sr */
    $sr = $session->shouldReceive('readResponse');
    $sr->andReturn('ICAP/1.0 204 No Content');
    /** @var \Mockery\Expectation $srel */
    $srel = $session->shouldReceive('release');
    $srel->andReturnNull();

    /** @var \Mockery\Expectation $pe */
    $pe = $parser->shouldReceive('parse');
    $pe->andReturn(new IcapResponse(204));

    /** @var \Mockery\Expectation $tos */
    $tos = $transport->shouldReceive('openSession');
    $tos->andReturn($session);
    // TransportInterface::request() is also part of SessionAwareTransport;
    // we don't expect it to be called in the preview flow, but define it
    // so Mockery doesn't complain.
    /** @var \Mockery\Expectation $tr */
    $tr = $transport->shouldReceive('request');
    $tr->never();

    $client = new IcapClient(
        $config,
        $transport,
        $formatter,
        $parser,
        optionsCache: $cache,
    );

    return [$client, $cache];
}

/**
 * Create a tiny temp file for preview tests.
 */
function makeTinyFile(): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'icap_preview_');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file');
    }
    // 64 bytes — small enough that any preview size >= 64 will trigger
    // the previewIsComplete=true path (single read, no continuation).
    file_put_contents($tmp, str_repeat('X', 64));
    return $tmp;
}

afterEach(function () {
    m::close();
});

it('uses the server-advertised Preview size from the OPTIONS cache when previewSize is null', function () {
    $captured = null;
    [$client, $cache] = makePreviewSizeClient($captured);

    // Seed the OPTIONS cache with a response that advertises Preview: 4096
    $optionsResponse = new IcapResponse(200, [
        'Preview'     => ['4096'],
        'Options-TTL' => ['3600'],
    ]);
    $cache->set('icap.example:1344/avscan', $optionsResponse, 3600);

    $tmp = makeTinyFile();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFileWithPreview('/avscan', $tmp)->await();
    });

    expect($captured)->toBeInstanceOf(IcapRequest::class);
    \assert($captured instanceof IcapRequest);
    // The Preview header on the wire must reflect the server's advertised value.
    expect($captured->headers['Preview'][0])->toBe('4096');

    @unlink($tmp);
});

it('falls back to 1024 when the OPTIONS cache has no entry for the service', function () {
    $captured = null;
    [$client] = makePreviewSizeClient($captured);
    // Cache is empty — no OPTIONS response for /avscan.

    $tmp = makeTinyFile();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFileWithPreview('/avscan', $tmp)->await();
    });

    expect($captured)->toBeInstanceOf(IcapRequest::class);
    \assert($captured instanceof IcapRequest);
    expect($captured->headers['Preview'][0])->toBe('1024');

    @unlink($tmp);
});

it('falls back to 1024 when the cached OPTIONS response has no Preview header', function () {
    $captured = null;
    [$client, $cache] = makePreviewSizeClient($captured);

    // OPTIONS response without a Preview header (server doesn't advertise it).
    $optionsResponse = new IcapResponse(200, [
        'Options-TTL' => ['3600'],
    ]);
    $cache->set('icap.example:1344/avscan', $optionsResponse, 3600);

    $tmp = makeTinyFile();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFileWithPreview('/avscan', $tmp)->await();
    });

    expect($captured)->toBeInstanceOf(IcapRequest::class);
    \assert($captured instanceof IcapRequest);
    expect($captured->headers['Preview'][0])->toBe('1024');

    @unlink($tmp);
});

it('falls back to 1024 when no OPTIONS cache is configured at all', function () {
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var SessionAwareTransport&\Mockery\MockInterface $transport */
    $transport = m::mock(SessionAwareTransport::class);
    /** @var TransportSession&\Mockery\MockInterface $session */
    $session = m::mock(TransportSession::class);

    $captured = null;
    /** @var \Mockery\Expectation $fe2 */
    $fe2 = $formatter->shouldReceive('format');
    $fe2->andReturnUsing(function (IcapRequest $req) use (&$captured): array {
        $captured = $req;
        return ['FORMATTED'];
    });
    /** @var \Mockery\Expectation $sw2 */
    $sw2 = $session->shouldReceive('write');
    $sw2->andReturnNull();
    /** @var \Mockery\Expectation $sr2 */
    $sr2 = $session->shouldReceive('readResponse');
    $sr2->andReturn('ICAP/1.0 204 No Content');
    /** @var \Mockery\Expectation $srel2 */
    $srel2 = $session->shouldReceive('release');
    $srel2->andReturnNull();
    /** @var \Mockery\Expectation $pe2 */
    $pe2 = $parser->shouldReceive('parse');
    $pe2->andReturn(new IcapResponse(204));
    /** @var \Mockery\Expectation $tos2 */
    $tos2 = $transport->shouldReceive('openSession');
    $tos2->andReturn($session);
    /** @var \Mockery\Expectation $tr2 */
    $tr2 = $transport->shouldReceive('request');
    $tr2->never();

    // No optionsCache parameter — null by default.
    $client = new IcapClient($config, $transport, $formatter, $parser);

    $tmp = makeTinyFile();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFileWithPreview('/avscan', $tmp)->await();
    });

    expect($captured)->toBeInstanceOf(IcapRequest::class);
    \assert($captured instanceof IcapRequest);
    expect($captured->headers['Preview'][0])->toBe('1024');

    @unlink($tmp);
});

it('honours an explicit previewSize even when the OPTIONS cache has a different value', function () {
    $captured = null;
    [$client, $cache] = makePreviewSizeClient($captured);

    // Cache advertises 4096, but the caller explicitly passes 2048.
    $optionsResponse = new IcapResponse(200, [
        'Preview'     => ['4096'],
        'Options-TTL' => ['3600'],
    ]);
    $cache->set('icap.example:1344/avscan', $optionsResponse, 3600);

    $tmp = makeTinyFile();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp) {
        $client->scanFileWithPreview('/avscan', $tmp, 2048)->await();
    });

    expect($captured)->toBeInstanceOf(IcapRequest::class);
    \assert($captured instanceof IcapRequest);
    // Explicit parameter wins over cached value.
    expect($captured->headers['Preview'][0])->toBe('2048');

    @unlink($tmp);
});

it('rejects previewSize of zero or negative when passed explicitly', function () {
    $captured = null;
    [$client] = makePreviewSizeClient($captured);

    $tmp = makeTinyFile();

    expect(fn () => $client->scanFileWithPreview('/avscan', $tmp, 0))
        ->toThrow(InvalidArgumentException::class);

    @unlink($tmp);
});

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
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
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
 * @return array{IcapClient, OneResponseOptionsCache}
 */
function makePreviewSizeClient(?IcapRequest &$capturedRequest, ?OneResponseOptionsCache $cache = null): array
{
    $config = new Config('icap.example');
    $cache ??= new OneResponseOptionsCache();

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
    $cache->seed($optionsResponse);

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

it('uses the advertised Preview size from a cached OPTIONS response in any spelling', function () {
    $captured = null;
    [$client, $cache] = makePreviewSizeClient($captured);
    $cache->seed(new IcapResponse(200, ['preview' => ['4096']]));

    $tmp = makeTinyFile();

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp) {
            $client->scanFileWithPreview('/avscan', $tmp)->await();
        });

        expect($captured)->toBeInstanceOf(IcapRequest::class);
        \assert($captured instanceof IcapRequest);
        expect($captured->headers['Preview'][0])->toBe('4096');
    } finally {
        @unlink($tmp);
    }
});

it('rejects a cached OPTIONS response whose Preview fields conflict', function () {
    $captured = null;
    [$client, $cache] = makePreviewSizeClient($captured);
    $cache->seed(new IcapResponse(200, [
        'Preview' => ['64'],
        'PREVIEW' => ['67108864'],
    ]));

    $tmp = makeTinyFile();

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp) {
            // The call does not throw for a cached answer it rejects; its Future does.
            $future = $client->scanFileWithPreview('/avscan', $tmp);
            expect(fn () => $future->await())->toThrow(IcapMalformedResponseException::class);
        });

        // Nothing was formatted, so no preview went out.
        expect($captured)->toBeNull();
    } finally {
        @unlink($tmp);
    }
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
    $cache->seed($optionsResponse);

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
    $cache->seed($optionsResponse);

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

/**
 * B06 — an announced preview is read into memory in one piece, so its size is
 * bounded: 0 to 1 MiB, checked before the file or a socket is touched.
 */
it('rejects an explicit previewSize outside 0..1 MiB before the file is opened', function () {
    $captured = null;
    [$client] = makePreviewSizeClient($captured);
    $path = ScriptedSource::add('bounds', 'abc');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path) {
            foreach ([-1, 1048577] as $size) {
                expect(fn () => $client->scanFileWithPreview('/avscan', $path, $size)->await())
                    ->toThrow(InvalidArgumentException::class, '', (string) $size);
            }
        });

        // Nothing was opened, nothing was formatted.
        expect(ScriptedSource::$opened)->toBe(0);
        expect($captured)->toBeNull();
    } finally {
        ScriptedSource::reset();
    }
});

/**
 * `Preview: 0` is a valid preview (the server decides on the headers, RFC 3507
 * §4.5); it must not be read with fread(…, 0), and 1 MiB is the largest size.
 */
it('accepts an explicit previewSize of 0 and of exactly 1 MiB', function () {
    foreach ([0, 1048576] as $size) {
        $captured = null;
        [$client] = makePreviewSizeClient($captured);
        $tmp = makeTinyFile();

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp, $size) {
                $client->scanFileWithPreview('/avscan', $tmp, $size)->await();
            });

            if (!$captured instanceof IcapRequest) {
                throw new RuntimeException('No request was formatted for previewSize ' . $size);
            }
            expect($captured->headers['Preview'])->toBe([(string) $size], (string) $size);

            $body = $captured->encapsulatedResponse?->body;
            if ($size === 0) {
                // 64 bytes follow the empty preview.
                expect($captured->previewIsComplete)->toBeFalse();
                expect($body)->toBe('');
            } else {
                expect($captured->previewIsComplete)->toBeTrue();
                expect($body)->toBe(str_repeat('X', 64));
            }
            expect($captured->encapsulatedResponse?->headers['Content-Length'] ?? null)->toBe(['64'], (string) $size);
        } finally {
            @unlink($tmp);
        }
    }
});

/**
 * B06 — a `Preview` value from a cached OPTIONS response is untrusted text.
 * It is read as a non-negative decimal before any integer cast; a valid value
 * above 1 MiB is clamped to it, and 0 stays 0.
 */
it('clamps a cached Preview value to 1 MiB and keeps 0', function () {
    $cases = [
        ['0', '0'],
        ['000', '0'],
        ['1048576', '1048576'],
        ['1048577', '1048576'],
        ['16777216', '1048576'],
    ];

    foreach ($cases as [$advertised, $expected]) {
        $captured = null;
        [$client, $cache] = makePreviewSizeClient($captured);
        $cache->seed(new IcapResponse(200, ['Preview' => [$advertised]]));
        $tmp = makeTinyFile();

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp) {
                $client->scanFileWithPreview('/avscan', $tmp)->await();
            });

            if (!$captured instanceof IcapRequest) {
                throw new RuntimeException('No request was formatted for Preview ' . $advertised);
            }
            expect($captured->headers['Preview'])->toBe([$expected], 'advertised ' . $advertised);
        } finally {
            @unlink($tmp);
        }
    }
});

it('rejects a cached Preview value that is not a non-negative decimal', function () {
    foreach (['abc', '-1', '1.5', '0x10', '1e3', '', ' 5', '+5'] as $advertised) {
        $captured = null;
        [$client, $cache] = makePreviewSizeClient($captured);
        $cache->seed(new IcapResponse(200, ['Preview' => [$advertised]]));
        $tmp = makeTinyFile();

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp, $advertised) {
                $future = $client->scanFileWithPreview('/avscan', $tmp);
                expect(fn () => $future->await())
                    ->toThrow(IcapMalformedResponseException::class, '', var_export($advertised, true));
            });

            // Rejected before anything was formatted or sent.
            expect($captured)->toBeNull();
        } finally {
            @unlink($tmp);
        }
    }
});

/*
 * The lookup of the preview size is part of the operation: a cache that fails it fails the Future, before a file is
 * opened or a request goes out; the call does not throw.
 */
it('fails the Future, not the call, when the cache fails the lookup of the preview size', function () {
    $cases = [
        'a failure of the backend' => new IcapStorageException('The cache is down.'),
        'a bug of the cache'       => new TypeError('A bug in the cache.'),
    ];

    foreach ($cases as $case => $failure) {
        /** @var OptionsCacheInterface&\Mockery\MockInterface $cache */
        $cache = m::mock(OptionsCacheInterface::class);
        /** @var \Mockery\Expectation $get */
        $get = $cache->shouldReceive('get');
        $get->andThrow($failure);
        $transport = new ScriptedTransport();
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        try {
            $path = ScriptedSource::add('preview-size', 'payload');
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $path, $failure, $case) {
                $future = $client->scanFileWithPreview('/svc', $path);

                expect(fn () => $future->await())->toThrow($failure::class, $failure->getMessage(), $case);
            });

            expect(ScriptedSource::$opened)->toBe(0, $case)
                ->and($transport->requests)->toBe([], $case);
        } finally {
            ScriptedSource::reset();
        }
    }
});

/**
 * B06 — the failure this guards against is fatal, so it runs in a child
 * process with a small memory limit and a deadline held by the parent. A
 * cached `Preview: 99999999999999999999` on a file of one byte used to make
 * the client ask PHP for that many bytes.
 */
it('survives a huge cached Preview value in a memory-limited child process', function () {
    $file = tempnam(sys_get_temp_dir(), 'icap_preview_');
    if ($file === false) {
        throw new RuntimeException('Failed to create temp file');
    }
    file_put_contents($file, 'x');

    $script = <<<'PHP'
require getenv('ICAP_AUTOLOAD');

$transport = new class implements Ndrstmr\Icap\Transport\TransportInterface {
    public string $preview = 'none';

    public function request(Ndrstmr\Icap\Config $config, iterable $rawRequest, ?Amp\Cancellation $cancellation = null): Amp\Future
    {
        $wire = '';
        foreach ($rawRequest as $chunk) {
            $wire .= $chunk;
        }
        if (preg_match('/Preview: (\d+)/', $wire, $m) === 1) {
            $this->preview = $m[1];
        }

        return Amp\Future::complete("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
    }
};

$cache = new Ndrstmr\Icap\Cache\InMemoryOptionsCache();
$cache->set(Ndrstmr\Icap\Cache\OptionsCacheKey::forService('icap.example', 1344, '/svc'), new Ndrstmr\Icap\DTO\IcapResponse(200, ['Preview' => [getenv('ICAP_PREVIEW')]]), 3600);

$client = new Ndrstmr\Icap\IcapClient(
    new Ndrstmr\Icap\Config('icap.example'),
    $transport,
    new Ndrstmr\Icap\RequestFormatter(),
    new Ndrstmr\Icap\ResponseParser(),
    optionsCache: $cache,
);
$client->scanFileWithPreview('/svc', getenv('ICAP_FILE'))->await();
echo $transport->preview;
PHP;

    $environment = array_merge(getenv(), [
        'ICAP_AUTOLOAD' => dirname(__DIR__) . '/vendor/autoload.php',
        'ICAP_FILE'     => $file,
        'ICAP_PREVIEW'  => '99999999999999999999',
    ]);
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=32M', '-r', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the child process.');
    }

    $stdout = '';
    $stderr = '';
    $exitCode = -1;
    $timedOut = false;

    try {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 20.0;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($file);
    }

    expect($timedOut)->toBeFalse();
    expect($exitCode)->toBe(0, 'child stderr: ' . substr($stderr, 0, 300));
    // The clamped size went out, and no allocation followed the advertised one.
    expect($stdout)->toBe('1048576');
});

/**
 * An OPTIONS cache that holds one response and gives it for whatever key the
 * client asks. These tests are about what the client does with a cached
 * response; which key it asks with is tested in OptionsCacheTest and in the
 * PSR adapter tests.
 */
final class OneResponseOptionsCache implements OptionsCacheInterface
{
    private ?IcapResponse $response = null;

    public function seed(IcapResponse $response): void
    {
        $this->response = $response;
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        return $this->response;
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $this->response = $response;
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->response = null;
    }
}

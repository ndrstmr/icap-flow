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
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

/**
 * RFC 3507 §4.10.2 specifies an Options-TTL header that lets the
 * server tell the client how long it can cache the OPTIONS response.
 * IcapClient honours this by consulting an OptionsCacheInterface on
 * each options() call and skipping the round trip on a cache hit.
 */

/**
 * @return array{IcapClient, RequestFormatterInterface&\Mockery\MockInterface, TransportInterface&\Mockery\MockInterface, ResponseParserInterface&\Mockery\MockInterface, OptionsCacheInterface}
 */
function makeOptionsClient(?OptionsCacheInterface $cache = null, ?IcapResponse $canned = null): array
{
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $cache ??= new InMemoryOptionsCache();
    $canned ??= new IcapResponse(200, ['Options-TTL' => ['3600']]);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn($canned);

    $client = new IcapClient(
        $config,
        $transport,
        $formatter,
        $parser,
        previewStrategy: null,
        logger: null,
        optionsCache: $cache,
    );

    return [$client, $formatter, $transport, $parser, $cache];
}

it('skips the transport on a cache hit', function () {
    $cache = new InMemoryOptionsCache();
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->once();
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->once();
    $t->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->once();
    $p->andReturn(new IcapResponse(200, ['Options-TTL' => ['3600']]));

    $client = new IcapClient($config, $transport, $formatter, $parser, optionsCache: $cache);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
        // Second call must be served from cache; the once() expectations
        // above will fail at m::close() if the transport / formatter /
        // parser are touched a second time.
        $client->options('/avscan')->await();
    });

    m::close();
    expect(true)->toBeTrue(); // Mockery verified; satisfy PHPUnit strict-mode
});

it('isolates cache entries per service path', function () {
    $cache = new InMemoryOptionsCache();
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->twice();
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->twice();
    $t->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->twice();
    $p->andReturn(new IcapResponse(200, ['Options-TTL' => ['3600']]));

    $client = new IcapClient($config, $transport, $formatter, $parser, optionsCache: $cache);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
        $client->options('/other-service')->await();
    });

    expect(true)->toBeTrue(); // explicit assertion to satisfy strict-mode

    m::close();
});

it('honours the Options-TTL header when storing', function () {
    $response = new IcapResponse(200, ['Options-TTL' => ['7']]);
    $cache = new RecordingOptionsCache();

    [$client] = makeOptionsClient($cache, $response);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
    });

    $entry = $cache->stored();
    expect($entry)->not->toBeNull()
        ->and($entry?->statusCode)->toBe(200);

    m::close();
});

it('reads Options-TTL and ISTag in any spelling when storing', function () {
    /** @var OptionsCacheInterface&\Mockery\MockInterface $cache */
    $cache = m::mock(OptionsCacheInterface::class);
    /** @var \Mockery\Expectation $get */
    $get = $cache->shouldReceive('get');
    $get->andReturnNull();
    /** @var \Mockery\Expectation $set */
    $set = $cache->shouldReceive('set');
    $set->once();
    $set->with(m::pattern('/^[0-9a-f]{64}\z/'), m::type(IcapResponse::class), 7, '"b01"');

    [$client] = makeOptionsClient($cache, (new ResponseParser())->parse(
        "ICAP/1.0 200 OK\r\noptions-ttl: 7\r\nistag: \"b01\"\r\nencapsulated: null-body=0\r\n\r\n",
    ));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
    });

    m::close();
    expect(true)->toBeTrue(); // Mockery verified; satisfy PHPUnit strict-mode
});

it('rejects an OPTIONS response with conflicting ISTag fields instead of caching it', function () {
    $cache = new RecordingOptionsCache();
    [$client] = makeOptionsClient($cache, new IcapResponse(200, [
        'ISTag'       => ['"old"'],
        'istag'       => ['"new"'],
        'Options-TTL' => ['60'],
    ]));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/avscan')->await())
            ->toThrow(IcapMalformedResponseException::class);
    });

    expect($cache->sets)->toBe([]);

    m::close();
});

it('treats an expired cache entry as a miss', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });
    // Entry with a TTL of 1 second, then advance the clock past it.
    $cache->set('k', new IcapResponse(200), 1);
    $now = 1002;
    expect($cache->get('k'))->toBeNull();
});

it('does not cache when Options-TTL is zero', function () {
    $response = new IcapResponse(200, ['Options-TTL' => ['0']]);
    $cache = new RecordingOptionsCache();

    [$client] = makeOptionsClient($cache, $response);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
    });

    // TTL=0 means the server does not want the response cached.
    expect($cache->stored())->toBeNull();

    m::close();
});

it('is a no-op when no cache is configured', function () {
    // Passing optionsCache: null re-enters the original behaviour.
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->twice();
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->twice();
    $t->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->twice();
    $p->andReturn(new IcapResponse(200, ['Options-TTL' => ['3600']]));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
        $client->options('/avscan')->await();
    });

    expect(true)->toBeTrue();

    m::close();
});

/*
 * M12: options() stores the OPTIONS response of a service and the preview
 * size lookup reads the advertised Preview from it, so both have to name the
 * same entry, and only for that service on that endpoint. The key is a hash
 * that holds nothing a PSR backend could refuse.
 */
it('uses one key for a service, in options() and in the preview size lookup', function () {
    $cache = new RecordingOptionsCache();
    $transport = new ScriptedTransport([
        "ICAP/1.0 200 OK\r\nOptions-TTL: 60\r\nPreview: 4096\r\nEncapsulated: null-body=0\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
        "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
    ]);
    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

    try {
        $path = ScriptedSource::add('preview', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path) {
            $client->options('/avscan')->await();
            $client->scanFileWithPreview('/avscan', $path)->await();
            $client->scanFileWithPreview('/other', $path)->await();
        });
    } finally {
        ScriptedSource::reset();
    }

    // options() looked and stored, the lookup for the same service looked again and found it,
    // the lookup for another service asked for another entry and found none.
    expect($cache->gets)->toHaveCount(3);
    expect($cache->sets)->toHaveCount(1);
    expect($cache->gets[0])->toBe($cache->sets[0]);
    expect($cache->gets[1])->toBe($cache->sets[0]);
    expect($cache->gets[2])->not->toBe($cache->sets[0]);
    expect(preg_match('/^[0-9a-f]{64}\z/', $cache->sets[0]))->toBe(1);
    expect($transport->requests[1])->toContain("Preview: 4096\r\n");
    expect($transport->requests[2])->toContain("Preview: 1024\r\n");
});

/**
 * An OPTIONS cache that keeps its entries in memory and remembers the keys
 * the client asked for and stored under.
 */
final class RecordingOptionsCache implements OptionsCacheInterface
{
    /** @var list<string> */
    public array $gets = [];

    /** @var list<string> */
    public array $sets = [];

    private InMemoryOptionsCache $inner;

    public function __construct()
    {
        $this->inner = new InMemoryOptionsCache();
    }

    /** The response of the first set(), if the cache still holds it. */
    public function stored(): ?IcapResponse
    {
        return $this->sets === [] ? null : $this->inner->get($this->sets[0]);
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $this->gets[] = $key;

        return $this->inner->get($key);
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $this->sets[] = $key;
        $this->inner->set($key, $response, $ttlSeconds, $istag);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }
}

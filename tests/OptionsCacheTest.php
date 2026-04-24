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
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
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
    $cache = new InMemoryOptionsCache();

    [$client] = makeOptionsClient($cache, $response);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
    });

    $entry = $cache->get('icap.example:1344/avscan');
    expect($entry)->not->toBeNull()
        ->and($entry?->statusCode)->toBe(200);

    m::close();
});

it('treats an expired cache entry as a miss', function () {
    $cache = new InMemoryOptionsCache();
    // Entry with a TTL of 1 second, then artificially advance the
    // cache's notion of "now" past it.
    $cache->set('k', new IcapResponse(200), 1);
    $cache->advanceClockForTesting(2);
    expect($cache->get('k'))->toBeNull();
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

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

use Amp\Socket\ClientTlsContext;
use Mockery as m;
use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\Cache\StoredEntryCases;
use Ndrstmr\Icap\Tests\IcapTestServer;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Psr\Log\AbstractLogger;

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

/*
 * N02: options() accepts a 200 and nothing else, for the answer of the server
 * and for the answer from the cache alike. What it rejects is not stored, and
 * a cached entry it would reject is not handed out as a success.
 */
it('does not store an answer that options() rejects and asks the server again', function () {
    // status => the exception options() raises for it
    $cases = [
        100 => IcapProtocolException::class,
        101 => IcapProtocolException::class,
        204 => IcapProtocolException::class,
        206 => IcapProtocolException::class,
        302 => IcapProtocolException::class,
        400 => IcapClientException::class,
        503 => IcapServerException::class,
        600 => IcapProtocolException::class,
    ];

    foreach ($cases as $status => $exception) {
        $reply = "ICAP/1.0 {$status} Reply\r\nOptions-TTL: 60\r\nISTag: \"v1\"\r\nPreview: 4096\r\nEncapsulated: null-body=0\r\n\r\n";
        $cache = new RecordingOptionsCache();
        $transport = new ScriptedTransport([$reply, $reply]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        $outcomes = [];
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, &$outcomes) {
            for ($call = 0; $call < 2; $call++) {
                try {
                    $client->options('/avscan')->await();
                    $outcomes[] = null;
                } catch (Throwable $e) {
                    $outcomes[] = $e::class;
                }
            }
        });

        expect($outcomes)->toBe([$exception, $exception], "status {$status}");
        expect($cache->sets)->toBe([], "status {$status}");
        expect($transport->requests)->toHaveCount(2, "status {$status}");
    }
});

/*
 * an answer from the cache is checked like one from the server, and one that fails the check is a miss: the
 * server is asked, and the answer it gives is returned and stored. This holds for any cache, not only for the adapters.
 */
it('does not hand out a cached answer that is no usable OPTIONS answer, and asks the server instead', function () {
    $unusable = StoredEntryCases::notKept() + [
        'a 100' => new IcapResponse(100, ['Preview' => ['4096']]),
        'a 302' => new IcapResponse(302, ['Preview' => ['4096']]),
        'a 400' => new IcapResponse(400, ['Preview' => ['4096']]),
        'a 600' => new IcapResponse(600, ['Preview' => ['4096']]),
    ];
    $live = "ICAP/1.0 200 OK\r\nOptions-TTL: 60\r\nPreview: 2048\r\nEncapsulated: null-body=0\r\n\r\n";

    foreach ($unusable as $case => $cached) {
        $cache = new SeededOptionsCache($cached);
        $transport = new ScriptedTransport([$live]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        $answer = null;
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, &$answer) {
            $answer = $client->options('/avscan')->await();
        });

        expect($answer?->headers['Preview'])->toBe(['2048'], $case);
        expect($transport->requests)->toHaveCount(1, $case);
        expect($cache->ttls)->toBe([60], $case);
    }

    // The same cache with a usable answer: served from the cache, the server is not asked.
    $cached = new IcapResponse(200, ['Preview' => ['4096']], 'opt-body');
    $transport = new ScriptedTransport([$live]);
    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: new SeededOptionsCache($cached));
    $answer = null;
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, &$answer) {
        $answer = $client->options('/avscan')->await();
    });
    expect($answer)->toBe($cached);
    expect($transport->requests)->toBe([]);
});

/*
 * a cached answer is read under the limits of the client that reads it, as the answer of the server is: the number of its
 * header lines, their length and the size of the response. What this client would have refused over the socket is a miss, so
 * the server is asked and the client refuses the answer as it does without a cache; what it would have taken is a hit. A client
 * with the default limits fills the cache, and the limits that decide are the least that the wire takes and one below.
 * An `opt-body` that is empty still ends in the last chunk, which a `null-body` does not have.
 */
it('hands out a cached answer only where the limits of this client take it from the server', function () {
    $fields = "Methods: RESPMOD\r\nOptions-TTL: 60\r\nPreview: 4096\r\nISTag: \"v1\"\r\n";
    $answers = [
        'without a body'     => "ICAP/1.0 200 OK\r\n{$fields}Encapsulated: null-body=0\r\n\r\n",
        'with a body'        => "ICAP/1.0 200 OK\r\n{$fields}Encapsulated: opt-body=0\r\n\r\n5\r\nhello\r\n0\r\n\r\n",
        'with an empty body' => "ICAP/1.0 200 OK\r\n{$fields}Encapsulated: opt-body=0\r\n\r\n0\r\n\r\n",
    ];
    $defaults = ['maxHeaderCount' => 100, 'maxHeaderLineLength' => 8192, 'maxResponseSize' => 1_048_576];
    $outcome = static function (IcapClient $client): string {
        try {
            return (string) $client->options('/avscan')->await()->statusCode;
        } catch (IcapMalformedResponseException) {
            return 'refused';
        }
    };

    foreach ($answers as $shape => $answer) {
        $lines = explode("\r\n", explode("\r\n\r\n", $answer, 2)[0]);
        // What the wire needs: the header lines (the status line is none), the longest line and every byte.
        $needs = [
            'maxHeaderCount'      => count($lines) - 1,
            'maxHeaderLineLength' => max(array_map(strlen(...), $lines)),
            'maxResponseSize'     => strlen($answer),
        ];
        $server = IcapTestServer::start(static fn (array $request): array => [$answer]);
        $clientWith = static fn (array $limits, ?OptionsCacheInterface $cache): IcapClient => new IcapClient(
            new Config('127.0.0.1', $server->port, maxResponseSize: $limits['maxResponseSize'], maxHeaderCount: $limits['maxHeaderCount'], maxHeaderLineLength: $limits['maxHeaderLineLength']),
            new AsyncAmpTransport(),
            new RequestFormatter(),
            new ResponseParser(maxHeaderCount: $limits['maxHeaderCount'], maxHeaderLineLength: $limits['maxHeaderLineLength']),
            optionsCache: $cache,
        );

        try {
            foreach ($needs as $kind => $least) {
                foreach ([$least => '200', $least - 1 => 'refused'] as $limit => $expected) {
                    $limits = [$kind => $limit] + $defaults;
                    $case = "$shape, $kind $limit";

                    // The edge of the wire is where this test says it is.
                    expect($outcome($clientWith($limits, null)))->toBe($expected, $case);

                    $cache = new InMemoryOptionsCache();
                    expect($outcome($clientWith($defaults, $cache)))->toBe('200', $case);
                    $asked = count($server->requests);

                    expect($outcome($clientWith($limits, $cache)))->toBe($expected, $case);
                    expect(count($server->requests) - $asked)->toBe($expected === '200' ? 0 : 1, $case);
                }
            }
        } finally {
            $server->stop();
        }
    }
});

/*
 * options() does not throw for what goes wrong with the cache: its Future fails with what the cache threw.
 */
it('delivers a failing cache through the Future of options(), unchanged', function () {
    $reply = "ICAP/1.0 200 OK\r\nOptions-TTL: 60\r\nEncapsulated: null-body=0\r\n\r\n";
    $storage = new IcapStorageException('The cache is down.');
    $bug = new TypeError('A bug in the cache.');

    // label => [the call that fails, what it throws, requests that reach the server]
    $cases = [
        'get'         => ['get', $storage, 0],
        'get, a bug'  => ['get', $bug, 0],
        'set'         => ['set', $storage, 1],
        'set, a bug'  => ['set', $bug, 1],
    ];

    foreach ($cases as $case => [$fails, $failure, $requests]) {
        /** @var OptionsCacheInterface&\Mockery\MockInterface $cache */
        $cache = m::mock(OptionsCacheInterface::class);
        /** @var \Mockery\Expectation $get */
        $get = $cache->shouldReceive('get');
        /** @var \Mockery\Expectation $set */
        $set = $cache->shouldReceive('set');
        if ($fails === 'get') {
            $get->andThrow($failure);
            $set->never();
        } else {
            $get->andReturn(null);
            $set->andThrow($failure);
        }
        $transport = new ScriptedTransport([$reply]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $failure, $case) {
            $baseline = $this->loopTimers();
            $future = $client->options('/svc');

            expect(fn () => $future->await())->toThrow($failure::class, $failure->getMessage(), $case);
            expect($this->loopTimers())->toBe($baseline, $case);
        });

        expect(count($transport->requests))->toBe($requests, $case);
    }

    m::close();
});

/*
 * A cache that takes the whole budget to say it has no entry has used the time of the call: the operation is over, and
 * no request goes out on its behalf.
 */
it('sends no request when the cache held the call up beyond its end and had no answer', function () {
    /** @var OptionsCacheInterface&\Mockery\MockInterface $cache */
    $cache = m::mock(OptionsCacheInterface::class);
    /** @var \Mockery\Expectation $get */
    $get = $cache->shouldReceive('get');
    $get->andReturnUsing(static function (): null {
        usleep(300_000);

        return null;
    });
    $cache->shouldNotReceive('set');
    $transport = new ScriptedTransport();
    $client = new IcapClient(new Config('icap.example', operationTimeout: 0.1), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(IcapTimeoutException::class);
    });

    expect($transport->requests)->toBe([]);
    m::close();
});

/*
 * Options-TTL is read as a decimal before any cast and ends at a limit of the client, an hour: the server can
 * shorten that time and not lengthen it. An answer without Options-TTL, or with one that is none, is not kept; the
 * RFC says such an answer does not expire, which the client does not take as a reason to keep it.
 */
it('keeps an answer for its Options-TTL, at most an hour, and not at all for a value that is no decimal', function () {
    // [Options-TTL of the answer, the seconds the cache is asked to keep it, or null for: it is not stored]
    $cases = [
        ['7', 7],
        ['3600', 3600],
        ['3601', 3600],
        ['007', 7],
        ['99999999999999999999', 3600],
        [str_repeat('9', 5000), 3600],
        ['0', null],
        ['000', null],
        ['', null],
        [' ', null],
        ['abc', null],
        ['12abc', null],
        ['1e3', null],
        ['-5', null],
        ['+5', null],
        ['1.5', null],
        ['0x10', null],
        [" 7", null],
        ["7\n", null],
        ["\u{661}\u{662}", null],
    ];

    foreach ($cases as [$ttl, $expected]) {
        $cache = new RecordingOptionsCache();
        [$client] = makeOptionsClient($cache, new IcapResponse(200, ['Options-TTL' => [$ttl]]));

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client) {
            $client->options('/avscan')->await();
        });

        expect($cache->ttls)->toBe($expected === null ? [] : [$expected], strlen($ttl) > 40 ? strlen($ttl) . ' digits' : var_export($ttl, true));
    }

    // No Options-TTL at all.
    $cache = new RecordingOptionsCache();
    [$client] = makeOptionsClient($cache, new IcapResponse(200, ['Methods' => ['RESPMOD']]));
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/avscan')->await();
    });
    expect($cache->ttls)->toBe([]);

    m::close();
});

it('returns an answer that is too big to be kept, and keeps nothing', function () {
    $answer = new IcapResponse(200, ['Options-TTL' => ['60']], str_repeat('x', 70_000));
    $cache = new RecordingOptionsCache();
    [$client] = makeOptionsClient($cache, $answer);

    $returned = null;
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, &$returned) {
        $returned = $client->options('/avscan')->await();
    });

    expect($returned?->body)->toBe(str_repeat('x', 70_000));
    expect($cache->ttls)->toBe([]);

    m::close();
});

/*
 * the key holds the security profile of the client (plain, or the TLS settings), so that clients that share one
 * cache do not share what a server told another profile. Equal settings are one profile, also in another object.
 */
it('keeps clients with other TLS settings apart in one cache, and shares an entry between equal settings', function () {
    $cache = new InMemoryOptionsCache();
    $reply = static fn (string $preview): string => "ICAP/1.0 200 OK\r\nOptions-TTL: 60\r\nPreview: {$preview}\r\nEncapsulated: null-body=0\r\n\r\n";
    $tls = static fn (string $ca): ClientTlsContext => (new ClientTlsContext('icap.example'))->withCaFile($ca);

    // [label, TLS context of the client, the preview its server advertises, whether it has to ask the server]
    $clients = [
        ['plain', null, '1000', true],
        ['TLS, CA a', $tls('/etc/ssl/ca-a.pem'), '2000', true],
        ['TLS, CA a, another object', $tls('/etc/ssl/ca-a.pem'), '9999', false],
        ['TLS, CA b', $tls('/etc/ssl/ca-b.pem'), '3000', true],
        ['plain again', null, '9999', false],
    ];

    $previews = [];
    foreach ($clients as [$label, $context, $preview, $asks]) {
        $transport = new ScriptedTransport([$reply($preview)]);
        $client = new IcapClient((new Config('icap.example'))->withTlsContext($context), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, &$previews, $label) {
            $previews[$label] = $client->options('/avscan')->await()->headers['Preview'][0];
        });

        expect($transport->requests)->toHaveCount($asks ? 1 : 0, $label);
    }

    expect($previews)->toBe([
        'plain'                     => '1000',
        'TLS, CA a'                 => '2000',
        'TLS, CA a, another object' => '2000',
        'TLS, CA b'                 => '3000',
        'plain again'               => '1000',
    ]);

    // The preview size comes from the entry of the client's own profile.
    try {
        foreach ([[$tls('/etc/ssl/ca-b.pem'), '3000'], [null, '1000'], [$tls('/etc/ssl/ca-c.pem'), '1024']] as [$context, $expected]) {
            $transport = new ScriptedTransport();
            $client = new IcapClient((new Config('icap.example'))->withTlsContext($context), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);
            $path = ScriptedSource::add('profile', 'payload');

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $path) {
                $client->scanFileWithPreview('/avscan', $path)->await();
            });

            expect($transport->requests[0])->toContain("Preview: {$expected}\r\n");
        }
    } finally {
        ScriptedSource::reset();
    }
});

/*
 * Options-TTL counts from the moment the answer came in. An answer that the client held for longer than that,
 * while foreign code ran, is not stored, and one that is stored has the time that is left.
 */
it('stores an answer for the time of its Options-TTL that is left when the client writes it', function () {
    $reply = static fn (int $ttl): string => "ICAP/1.0 200 OK\r\nOptions-TTL: {$ttl}\r\nEncapsulated: null-body=0\r\n\r\n";

    // [Options-TTL, seconds the logger holds the answer, the seconds the cache is asked to keep it, or null for: not stored]
    $cases = [
        'no delay'            => [3, 0.0, 3],
        'less than a second'  => [3, 0.3, 3],
        'a second of delay'   => [3, 1.05, 2],
        'the whole TTL'       => [1, 1.05, null],
    ];

    foreach ($cases as $case => [$ttl, $hold, $expected]) {
        $cache = new RecordingOptionsCache();
        $client = new IcapClient(new Config('icap.example'), new ScriptedTransport([$reply($ttl)]), new RequestFormatter(), new ResponseParser(), logger: new OptionsHoldingLogger($hold), optionsCache: $cache);

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client) {
            $client->options('/svc')->await();
        });

        expect($cache->ttls)->toBe($expected === null ? [] : [$expected], $case);
    }
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

    /** @var list<int> the seconds each set() was asked to keep its entry */
    public array $ttls = [];

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
        $this->ttls[] = $ttlSeconds;
        $this->inner->set($key, $response, $ttlSeconds, $istag);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }
}

/**
 * An OPTIONS cache that answers every key with the one response it was given, until a set() replaces it, and records
 * the seconds each set() asked for.
 */
final class SeededOptionsCache implements OptionsCacheInterface
{
    /** @var list<int> */
    public array $ttls = [];

    public function __construct(private ?IcapResponse $response)
    {
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        return $this->response;
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $this->ttls[] = $ttlSeconds;
        $this->response = $response;
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->response = null;
    }
}

/**
 * A logger that holds the event loop for a while once the answer to the request is in.
 */
final class OptionsHoldingLogger extends AbstractLogger
{
    public function __construct(private readonly float $seconds)
    {
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string) $message === 'ICAP request completed') {
            usleep((int) ($this->seconds * 1_000_000));
        }
    }
}

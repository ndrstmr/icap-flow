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

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
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
 * The IcapClient API must accept an optional Amp\Cancellation that the
 * caller can use to abort an in-flight scan from the outside (e.g.
 * an HTTP client cancelled the upload).
 */

it('options() forwards a user-supplied Cancellation to the transport', function () {
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $userCancellation = (new DeferredCancellation())->getCancellation();

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);

    $captured = null;
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->withArgs(function ($cfg, $chunks, $cancellation) use (&$captured) {
        $captured = $cancellation;
        return true;
    });
    $t->andReturn(\Amp\Future::complete('RESP'));

    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $userCancellation, &$captured) {
        $client->options('/svc', $userCancellation)->await();
        expect($captured)->toBe($userCancellation);
    });

    m::close();
});

it('scanFile() forwards a user-supplied Cancellation', function () {
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload');

    // A DeferredCancellation cancels when it is destroyed, so the owner has
    // to stay alive: the token must be a live one, not a cancelled one.
    $deferred = new DeferredCancellation();
    $userCancellation = $deferred->getCancellation();

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);

    $seen = null;
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->withArgs(function ($cfg, $chunks, $c) use (&$seen) {
        $seen = $c;
        return true;
    });
    $t->andReturn(\Amp\Future::complete('RESP'));

    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $userCancellation, &$seen) {
            $client->scanFile('/svc', $tmp, [], $userCancellation)->await();
            expect($seen)->toBe($userCancellation);
        });
    } finally {
        @unlink($tmp);
    }
    m::close();
});

it('propagates cancellation as Amp\\CancelledException when the cancellation fires', function () {
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $deferred = new DeferredCancellation();
    $deferred->cancel();
    $userCancellation = $deferred->getCancellation();

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturnUsing(function (Config $cfg, iterable $chunks, ?Cancellation $c) {
        // Real transport wraps the read loop with the cancellation; our
        // mock simulates the transport observing the already-cancelled
        // token by returning an errored Future.
        $c?->throwIfRequested();
        return \Amp\Future::complete('RESP');
    });

    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $userCancellation) {
        expect(fn () => $client->options('/svc', $userCancellation)->await())
            ->toThrow(CancelledException::class);
    });

    m::close();
});

/**
 * M08 — a cancellation that was requested before the call opens no file, and
 * one that fires while the request is in flight still gives the handle back.
 */
it('opens no file for a cancellation that was already requested', function () {
    $deferred = new DeferredCancellation();
    $deferred->cancel();
    $client = new IcapClient(
        new Config('icap.example'),
        new ScriptedTransport(),
        new RequestFormatter(),
        new ResponseParser(),
    );

    try {
        $path = ScriptedSource::add('pre-cancelled', 'payload');
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $deferred) {
            expect(fn () => $client->scanFile('/svc', $path, [], $deferred->getCancellation())->await())
                ->toThrow(CancelledException::class);
            expect(fn () => $client->scanFileWithPreview('/svc', $path, 4, [], $deferred->getCancellation())->await())
                ->toThrow(CancelledException::class);
        });

        expect(ScriptedSource::$opened)->toBe(0);
    } finally {
        ScriptedSource::reset();
    }
});

it('gives the file handle back when the cancellation fires during the request', function () {
    // Failed futures and exceptions keep their trace arguments, and with them
    // the stream: the handle has to be closed regardless.
    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];

    try {
        foreach (['scanFile', 'scanFileWithPreview'] as $method) {
            $deferred = new DeferredCancellation();
            $transport = new class ($deferred) implements TransportInterface {
                public function __construct(private DeferredCancellation $deferred)
                {
                }

                #[\Override]
                public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
                {
                    foreach ($rawRequest as $_) {
                        // a real transport writes every chunk
                    }
                    $this->deferred->cancel();
                    $cancellation?->throwIfRequested();

                    return Future::complete("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
                }
            };
            $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());
            $path = ScriptedSource::add('in-flight', 'payload');
            $cancellation = $deferred->getCancellation();

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $method, $path, $cancellation, &$held) {
                $future = $method === 'scanFile'
                    ? $client->scanFile('/svc', $path, [], $cancellation)
                    : $client->scanFileWithPreview('/svc', $path, 4, [], $cancellation);
                $held[] = $future;

                $cancelled = false;
                try {
                    $future->await();
                } catch (CancelledException $e) {
                    $held[] = $e;
                    $cancelled = true;
                }

                expect($cancelled)->toBeTrue($method);
                expect(ScriptedSource::$opened)->toBe(1, $method);
                expect(ScriptedSource::$closed)->toBe(1, $method);
            });

            ScriptedSource::reset();
        }
    } finally {
        ScriptedSource::reset();
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
});

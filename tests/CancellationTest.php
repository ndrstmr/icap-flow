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
use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
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

    $userCancellation = (new DeferredCancellation())->getCancellation();

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

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $userCancellation, &$seen) {
        $client->scanFile('/svc', $tmp, [], $userCancellation)->await();
        expect($seen)->toBe($userCancellation);
    });

    unlink($tmp);
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

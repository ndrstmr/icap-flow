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
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClientInterface;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\Tests\AsyncTestCase;

uses(AsyncTestCase::class);

/**
 * RetryingIcapClient is a decorator that retries 5xx responses with
 * exponential backoff. 4xx and other failure types propagate
 * immediately — fail-secure on client-side errors, retry-on-transient
 * for server-side errors.
 */

it('retries on 503 and returns the eventual success', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    $clean = new ScanResult(false, null, new IcapResponse(204));

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(3);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('busy', 503)),
        \Amp\Future::error(new IcapServerException('still busy', 503)),
        \Amp\Future::complete($clean),
    );

    $client = new RetryingIcapClient($inner, maxAttempts: 3, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $clean) {
        $res = $client->options('/svc')->await();
        expect($res)->toBe($clean);
    });

    m::close();
});

it('gives up after maxAttempts and rethrows the last server exception', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(3);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('one', 503)),
        \Amp\Future::error(new IcapServerException('two', 503)),
        \Amp\Future::error(new IcapServerException('three', 503)),
    );

    $client = new RetryingIcapClient($inner, maxAttempts: 3, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        try {
            $client->options('/svc')->await();
            expect(false)->toBeTrue('expected IcapServerException');
        } catch (IcapServerException $e) {
            expect($e->getMessage())->toBe('three');
        }
    });

    m::close();
});

it('does NOT retry on 4xx — those are caller errors, not transient', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->once();
    $exp->andReturn(\Amp\Future::error(new IcapClientException('bad request', 400)));

    $client = new RetryingIcapClient($inner, maxAttempts: 5, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())
            ->toThrow(IcapClientException::class);
    });

    m::close();
});

it('uses exponential backoff (2x by default) capped by maxDelaySeconds', function () {
    $delays = [];
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(4);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('1', 503)),
        \Amp\Future::error(new IcapServerException('2', 503)),
        \Amp\Future::error(new IcapServerException('3', 503)),
        \Amp\Future::complete(new ScanResult(false, null, new IcapResponse(204))),
    );

    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 4,
        baseDelaySeconds: 0.1,
        maxDelaySeconds: 0.25,
        backoffFactor: 2.0,
        sleeper: function (float $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/svc')->await();
    });

    // After 1st failure: 0.1s, after 2nd: 0.2s, after 3rd: capped at 0.25s.
    expect($delays)->toBe([0.1, 0.2, 0.25]);

    m::close();
});

it('forwards request, scanFile and scanFileWithPreview through the decorator', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    $clean = new ScanResult(false, null, new IcapResponse(204));
    $req = new \Ndrstmr\Icap\DTO\IcapRequest('OPTIONS', 'icap://x/svc');

    /** @var \Mockery\Expectation $r1 */
    $r1 = $inner->shouldReceive('request');
    $r1->once();
    $r1->andReturn(\Amp\Future::complete($clean));
    /** @var \Mockery\Expectation $r2 */
    $r2 = $inner->shouldReceive('scanFile');
    $r2->once();
    $r2->andReturn(\Amp\Future::complete($clean));
    /** @var \Mockery\Expectation $r3 */
    $r3 = $inner->shouldReceive('scanFileWithPreview');
    $r3->once();
    $r3->andReturn(\Amp\Future::complete($clean));

    $client = new RetryingIcapClient($inner);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $req) {
        $client->request($req)->await();
        $client->scanFile('/svc', '/tmp/x')->await();
        $client->scanFileWithPreview('/svc', '/tmp/x')->await();
    });

    m::close();
});

it('rejects nonsensical configuration', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    expect(fn () => new RetryingIcapClient($inner, maxAttempts: 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RetryingIcapClient($inner, baseDelaySeconds: -1.0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RetryingIcapClient($inner, backoffFactor: 0.0))
        ->toThrow(InvalidArgumentException::class);
});

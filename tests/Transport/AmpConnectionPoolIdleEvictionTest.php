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
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;

uses(AsyncTestCase::class);

/**
 * v2.2-Q — idle-eviction on acquire().
 *
 * Long-running PHP workers (Swoole, RoadRunner, ReactPHP) keep the
 * event loop alive for hours. Without eviction, idle sockets
 * accumulate and go stale silently — the server closes its end but
 * isClosed() may not reflect that until the next write attempt.
 * The pool now records when each socket became idle and drops entries
 * older than maxIdleSeconds on the next acquire().
 */

it('evicts idle sockets that exceed maxIdleSeconds on acquire', function () {
    $now = 1000.0;
    [, $stale] = Socket\createSocketPair();
    [, $fresh] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$stale, $fresh];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        maxIdleSeconds: 30.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $fresh, &$now) {
        $s = $pool->acquire($config);
        $pool->release($config, $s);

        // Advance clock past maxIdleSeconds.
        $now = 1031.0;

        // Next acquire must skip the stale entry and connect fresh.
        $next = $pool->acquire($config);
        expect($next)->toBe($fresh);
    });

    // The stale socket must have been closed by the eviction.
    expect($stale->isClosed())->toBeTrue();
});

it('keeps idle sockets that are within maxIdleSeconds', function () {
    $now = 1000.0;
    [, $socket] = Socket\createSocketPair();
    [, $spare] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$socket, $spare];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        maxIdleSeconds: 30.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $socket, &$now) {
        $s = $pool->acquire($config);
        $pool->release($config, $s);

        // Advance clock but stay within maxIdleSeconds.
        $now = 1029.0;

        $next = $pool->acquire($config);
        // Same socket reused — not evicted.
        expect($next)->toBe($socket);
    });

    // Spare was never needed.
    expect($spare->isClosed())->toBeFalse();
});

it('defaults maxIdleSeconds to 30 when not specified', function () {
    $now = 1000.0;
    [, $stale] = Socket\createSocketPair();
    [, $fresh] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$stale, $fresh];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        // maxIdleSeconds not set — default 30.
        clock: function () use (&$now): float {
            return $now;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $fresh, &$now) {
        $s = $pool->acquire($config);
        $pool->release($config, $s);

        // 31 seconds later — exceeds the default 30s.
        $now = 1031.0;

        $next = $pool->acquire($config);
        expect($next)->toBe($fresh);
    });

    expect($stale->isClosed())->toBeTrue();
});

it('rejects maxIdleSeconds of zero or negative', function () {
    expect(fn () => new AmpConnectionPool(maxIdleSeconds: 0.0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AmpConnectionPool(maxIdleSeconds: -5.0))
        ->toThrow(InvalidArgumentException::class);
});

it('evicts multiple stale sockets and connects fresh on acquire', function () {
    $now = 1000.0;
    [, $stale1] = Socket\createSocketPair();
    [, $stale2] = Socket\createSocketPair();
    [, $fresh] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$stale1, $stale2, $fresh];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        maxIdleSeconds: 10.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $fresh, &$now) {
        $a = $pool->acquire($config);
        $b = $pool->acquire($config);
        $pool->release($config, $a);
        $pool->release($config, $b);

        // Both are now stale.
        $now = 1011.0;

        $next = $pool->acquire($config);
        expect($next)->toBe($fresh);
    });

    expect($stale1->isClosed())->toBeTrue()
        ->and($stale2->isClosed())->toBeTrue();
});

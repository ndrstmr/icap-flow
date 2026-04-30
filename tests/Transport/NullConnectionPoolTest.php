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
use Ndrstmr\Icap\Transport\ConnectionPoolInterface;
use Ndrstmr\Icap\Transport\NullConnectionPool;

uses(AsyncTestCase::class);

/**
 * v2.2-E2 — NullConnectionPool: no keep-alive, every acquire() opens
 * a fresh connection, every release() closes it. Useful for testing
 * and explicit pool-off configurations.
 */

it('implements ConnectionPoolInterface', function () {
    $pool = new NullConnectionPool(
        connector: fn () => Socket\createSocketPair()[1],
    );
    expect($pool)->toBeInstanceOf(ConnectionPoolInterface::class);
});

it('never reuses a socket — every acquire returns a fresh connection', function () {
    /** @var SocketInterface[] $queue */
    $queue = [];
    for ($i = 0; $i < 3; $i++) {
        [, $end] = Socket\createSocketPair();
        $queue[] = $end;
    }
    $created = $queue;

    $config = new Config('icap.example');
    $pool = new NullConnectionPool(
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $created) {
        $a = $pool->acquire($config);
        $pool->release($config, $a);
        // Even though $a was released, the next acquire must NOT return it.
        $b = $pool->acquire($config);
        expect($b)->not->toBe($created[0])
            ->and($b)->toBe($created[1]);
    });
});

it('closes the socket on release', function () {
    [, $socket] = Socket\createSocketPair();

    $config = new Config('icap.example');
    $pool = new NullConnectionPool(
        connector: fn () => $socket,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $s = $pool->acquire($config);
        expect($s->isClosed())->toBeFalse();
        $pool->release($config, $s);
        expect($s->isClosed())->toBeTrue();
    });
});

it('close() is a no-op — no pooled sockets to shut down', function () {
    $config = new Config('icap.example');
    [, $socket] = Socket\createSocketPair();

    $pool = new NullConnectionPool(
        connector: fn () => $socket,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $s = $pool->acquire($config);
        $pool->close();
        // The in-flight socket is NOT affected by close().
        expect($s->isClosed())->toBeFalse();
        // But release after close still closes the socket.
        $pool->release($config, $s);
        expect($s->isClosed())->toBeTrue();
    });
});

it('uses the default connector when none is injected', function () {
    // Verify the no-arg constructor doesn't throw.
    $pool = new NullConnectionPool();
    expect($pool)->toBeInstanceOf(ConnectionPoolInterface::class);
});

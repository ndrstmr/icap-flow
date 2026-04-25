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
 * v2.1 connection pooling. After the M3 follow-up dropped the
 * Connection: close hack, the transport can hand idle sockets back
 * to a pool keyed by host:port[:tls] and reuse them for subsequent
 * requests.
 */

it('reuses a released socket on the next acquire', function () {
    [, $serverEnd1] = Socket\createSocketPair();
    [, $serverEnd2] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$serverEnd1, $serverEnd2];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        // Inject a connector that hands out our pre-built sockets so
        // we don't need a live TCP listener to exercise the pool.
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $pool->release($config, $first);

        $second = $pool->acquire($config);
        // Identity: the same Socket instance came back from the pool.
        expect($second)->toBe($first);

        // Counter check: there should still be 1 socket in the
        // connector queue — the pool didn't connect a fresh one.
    });

    expect($queue)->toHaveCount(1);
});

it('opens a fresh connection when the pool is empty', function () {
    [, $serverEndA] = Socket\createSocketPair();
    [, $serverEndB] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$serverEndA, $serverEndB];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $second = $pool->acquire($config); // pool was empty after first acquire
        expect($second)->not->toBe($first);
    });
});

it('caps idle connections at maxConnectionsPerHost', function () {
    /** @var SocketInterface[] $queue */
    $queue = [];
    for ($i = 0; $i < 5; $i++) {
        [, $end] = Socket\createSocketPair();
        $queue[] = $end;
    }
    $created = $queue;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $sockets = [
            $pool->acquire($config),
            $pool->acquire($config),
            $pool->acquire($config),
        ];
        // Release all three — the third must be closed (cap = 2).
        foreach ($sockets as $s) {
            $pool->release($config, $s);
        }
    });

    // The third created socket should have been closed by the pool.
    expect($created[2]->isClosed())->toBeTrue();
    // The first two should still be open in the idle pool.
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse();
});

it('skips a closed idle socket and connects fresh', function () {
    [, $stale] = Socket\createSocketPair();
    [, $fresh] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$stale, $fresh];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $fresh) {
        $first = $pool->acquire($config);
        // Simulate the server closing the connection between the two
        // requests (or a timeout intermediary dropping the socket).
        $first->close();
        $pool->release($config, $first); // pool sees it's closed → drops it

        $second = $pool->acquire($config);
        expect($second)->toBe($fresh);
    });
});

it('close() shuts down every pooled socket', function () {
    [, $a] = Socket\createSocketPair();
    [, $b] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$a, $b];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $second = $pool->acquire($config);
        $pool->release($config, $first);
        $pool->release($config, $second);
        $pool->close();
    });

    expect($a->isClosed())->toBeTrue()
        ->and($b->isClosed())->toBeTrue();
});

it('isolates idle sockets per host:port', function () {
    [, $h1] = Socket\createSocketPair();
    [, $h2] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$h1, $h2];

    $configA = new Config('host-a');
    $configB = new Config('host-b');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $configA, $configB, $h1, $h2) {
        $a = $pool->acquire($configA);
        $b = $pool->acquire($configB);
        $pool->release($configA, $a);
        $pool->release($configB, $b);
        // Re-acquire from B should NOT return the A socket.
        expect($pool->acquire($configB))->toBe($h2);
        expect($pool->acquire($configA))->toBe($h1);
    });
});

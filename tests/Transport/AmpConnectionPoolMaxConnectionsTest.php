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
 * v2.2-L — OPTIONS Max-Connections: the pool can be configured with a
 * server-advertised Max-Connections value (RFC 3507 §4.10.2). The
 * effective idle cap becomes min(localCap, serverMaxConnections).
 */

/**
 * @return list<SocketInterface>
 */
function createSocketQueue(int $count): array
{
    $queue = [];
    for ($i = 0; $i < $count; $i++) {
        [, $end] = Socket\createSocketPair();
        $queue[] = $end;
    }
    return $queue;
}

it('reduces the effective idle cap when serverMaxConnections < localCap', function () {
    $created = createSocketQueue(4);
    $queue = $created;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        serverMaxConnections: 2,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $sockets = [
            $pool->acquire($config),
            $pool->acquire($config),
            $pool->acquire($config),
        ];
        // Release all three — effective cap is min(8, 2) = 2.
        foreach ($sockets as $s) {
            $pool->release($config, $s);
        }
    });

    // The third socket must be closed (effective cap = 2).
    expect($created[2]->isClosed())->toBeTrue();
    // The first two should still be idle.
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse();
});

it('uses localCap when serverMaxConnections is higher', function () {
    $created = createSocketQueue(4);
    $queue = $created;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        serverMaxConnections: 100,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $sockets = [
            $pool->acquire($config),
            $pool->acquire($config),
            $pool->acquire($config),
        ];
        foreach ($sockets as $s) {
            $pool->release($config, $s);
        }
    });

    // localCap (2) wins — third socket closed.
    expect($created[2]->isClosed())->toBeTrue();
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse();
});

it('ignores serverMaxConnections when null (default)', function () {
    $created = createSocketQueue(4);
    $queue = $created;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 3,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
        // serverMaxConnections defaults to null — no server constraint.
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $sockets = [
            $pool->acquire($config),
            $pool->acquire($config),
            $pool->acquire($config),
        ];
        foreach ($sockets as $s) {
            $pool->release($config, $s);
        }
    });

    // All three fit within localCap (3).
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse()
        ->and($created[2]->isClosed())->toBeFalse();
});

it('rejects serverMaxConnections of zero or negative', function () {
    expect(fn () => new AmpConnectionPool(serverMaxConnections: 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AmpConnectionPool(serverMaxConnections: -1))
        ->toThrow(InvalidArgumentException::class);
});

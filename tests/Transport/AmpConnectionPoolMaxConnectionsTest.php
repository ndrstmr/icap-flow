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

it('tuneFromOptions extracts Max-Connections from an OPTIONS response', function () {
    $created = createSocketQueue(4);
    $queue = $created;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
    );

    // Simulate an OPTIONS response with Max-Connections: 2.
    $optionsResponse = new \Ndrstmr\Icap\DTO\IcapResponse(200, [
        'Max-Connections' => ['2'],
        'Options-TTL'     => ['3600'],
    ]);
    $pool->tuneFromOptions($optionsResponse);

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

    // Effective cap = min(8, 2) = 2 → third socket closed.
    expect($created[2]->isClosed())->toBeTrue();
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse();
});

it('tuneFromOptions ignores a response without Max-Connections header', function () {
    $created = createSocketQueue(4);
    $queue = $created;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 3,
        connector: function () use (&$queue): SocketInterface {
            return array_shift($queue) ?? throw new RuntimeException('exhausted');
        },
    );

    // OPTIONS response without Max-Connections → no change.
    $pool->tuneFromOptions(new \Ndrstmr\Icap\DTO\IcapResponse(200, [
        'Options-TTL' => ['3600'],
    ]));

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

    // All three fit within localCap (3) — no server constraint applied.
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse()
        ->and($created[2]->isClosed())->toBeFalse();
});

it('tuneFromOptions can be called multiple times to update the cap dynamically', function () {
    $config = new Config('icap.example');
    $socketIndex = 0;

    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: function () use (&$socketIndex): SocketInterface {
            $socketIndex++;
            [, $end] = Socket\createSocketPair();
            return $end;
        },
    );

    // First tune: server allows 3.
    $pool->tuneFromOptions(new \Ndrstmr\Icap\DTO\IcapResponse(200, [
        'Max-Connections' => ['3'],
    ]));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $s1 = $pool->acquire($config);
        $s2 = $pool->acquire($config);
        $s3 = $pool->acquire($config);
        $s4 = $pool->acquire($config);
        $pool->release($config, $s1);
        $pool->release($config, $s2);
        $pool->release($config, $s3);
        $pool->release($config, $s4); // 4th exceeds cap 3 → closed
        expect($s4->isClosed())->toBeTrue();
        expect($s1->isClosed())->toBeFalse();
    });

    // Second tune: server reduces to 1.
    $pool->tuneFromOptions(new \Ndrstmr\Icap\DTO\IcapResponse(200, [
        'Max-Connections' => ['1'],
    ]));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        // Drain existing idle sockets (3 from above).
        $a = $pool->acquire($config);
        $b = $pool->acquire($config);
        $c = $pool->acquire($config);
        $pool->release($config, $a);
        $pool->release($config, $b); // 2nd exceeds cap 1 → closed
        expect($b->isClosed())->toBeTrue();
        $pool->release($config, $c); // also exceeds → closed
        expect($c->isClosed())->toBeTrue();
        expect($a->isClosed())->toBeFalse();
    });
});

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

use Amp\CancelledException;
use Amp\Socket\Socket as SocketInterface;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;

use function Amp\delay;

uses(AsyncTestCase::class);

/**
 * v2.2-L — OPTIONS Max-Connections: the pool can be configured with a
 * server-advertised Max-Connections value (RFC 3507 §4.10.2). The
 * effective cap on the connections to an endpoint becomes
 * min(localCap, serverMaxConnections). The server's number can only lower
 * what the pool allows itself (M04, N06).
 */

/**
 * How many connections to an endpoint the pool grants at once: it is asked until it refuses.
 *
 * @return list<SocketInterface>
 */
function poolGrants(AmpConnectionPool $pool, Config $config, int $limit = 12): array
{
    $granted = [];
    for ($i = 0; $i < $limit; $i++) {
        try {
            $granted[] = $pool->acquire($config);
        } catch (IcapConnectionException) {
            break;
        }
    }

    return $granted;
}

it('grants no more connections than serverMaxConnections when that is lower than the local cap', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
        serverMaxConnections: 2,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(2);
    });
});

it('keeps the local cap when serverMaxConnections is higher', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: fn (): SocketInterface => $this->liveSocket(),
        serverMaxConnections: 100,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(2);
    });
});

it('ignores serverMaxConnections when null (default)', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 3,
        connector: fn (): SocketInterface => $this->liveSocket(),
        // serverMaxConnections defaults to null — no server constraint.
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(3);
    });
});

it('rejects serverMaxConnections of zero or negative', function () {
    expect(fn () => new AmpConnectionPool(serverMaxConnections: 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AmpConnectionPool(serverMaxConnections: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('tuneFromOptions extracts Max-Connections from an OPTIONS response', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
    );

    // Simulate an OPTIONS response with Max-Connections: 2.
    $pool->tuneFromOptions(new IcapResponse(200, [
        'Max-Connections' => ['2'],
        'Options-TTL'     => ['3600'],
    ]));

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(2);
    });
});

it('tuneFromOptions ignores a response without Max-Connections header', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 3,
        connector: fn (): SocketInterface => $this->liveSocket(),
    );

    // OPTIONS response without Max-Connections → no change.
    $pool->tuneFromOptions(new IcapResponse(200, [
        'Options-TTL' => ['3600'],
    ]));

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(3);
    });
});

it('tuneFromOptions reads Max-Connections in any spelling and rejects conflicting fields', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
    );

    expect(fn () => $pool->tuneFromOptions(new IcapResponse(200, [
        'Max-Connections' => ['2'],
        'max-connections' => ['50'],
    ])))->toThrow(IcapMalformedResponseException::class);

    $pool->tuneFromOptions(new IcapResponse(200, [
        'max-connections' => ['2'],
    ]));

    $this->runAsyncTest(function () use ($pool, $config) {
        expect(poolGrants($pool, $config))->toHaveCount(2);
    });
});

it('tuneFromOptions can be called multiple times to update the cap dynamically', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
    );

    // First tune: server allows 3.
    $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['3']]));

    $this->runAsyncTest(function () use ($pool, $config) {
        $granted = poolGrants($pool, $config);
        expect($granted)->toHaveCount(3);
        foreach ($granted as $socket) {
            $pool->release($config, $socket);
        }

        // Second tune: the server reduces to 1. The two idle sockets above the new cap are closed at once.
        $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['1']]));
        // The oldest idle sockets give way; the one that was returned last is the one that stays.
        expect($granted[0]->isClosed())->toBeTrue()
            ->and($granted[1]->isClosed())->toBeTrue()
            ->and($granted[2]->isClosed())->toBeFalse();
        expect(poolGrants($pool, $config))->toBe([$granted[2]]);
    });
});

/*
 * N06 — the number is checked as a positive decimal before it is cast: (int) '2.5' is 2, (int) '2abc'
 * is 2, (int) '1e3' is 1000. A value that is none is a malformed response, not a setting.
 */
it('takes Max-Connections only as a positive decimal number and leaves the cap alone otherwise', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $invalid = ['1e3', '2.5', '2abc', '-1', '+2', '0', '00', '0x10', '', ' ', ' 2', '2 ', "2\n", 'two', '٣', '1 2', '2,3', '1000000000', '99999999999999999999'];

    foreach ($invalid as $value) {
        $pool = new AmpConnectionPool(maxConnectionsPerHost: 5, connector: fn (): SocketInterface => $this->liveSocket());

        expect(fn () => $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => [$value]])))
            ->toThrow(IcapMalformedResponseException::class, '', var_export($value, true));

        $this->runAsyncTest(function () use ($pool, $config, $value) {
            expect(poolGrants($pool, $config))->toHaveCount(5, var_export($value, true));
        });
    }

    // Valid numbers: a leading zero is a decimal digit, and one beyond the pool's cap cannot lower anything.
    foreach (['2' => 2, '007' => 5, '999999999' => 5, '5' => 5, '1' => 1] as $value => $granted) {
        $pool = new AmpConnectionPool(maxConnectionsPerHost: 5, connector: fn (): SocketInterface => $this->liveSocket());
        $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => [(string) $value]]));

        $this->runAsyncTest(function () use ($pool, $config, $granted, $value) {
            expect(poolGrants($pool, $config))->toHaveCount($granted, "Max-Connections: {$value}");
        });
    }
});

it('applies Max-Connections to the endpoint it was advertised for and to every endpoint when no endpoint is named', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $named = new AmpConnectionPool(maxConnectionsPerHost: 5, maxConnections: 20, connector: fn (): SocketInterface => $this->liveSocket());
    $global = new AmpConnectionPool(maxConnectionsPerHost: 5, maxConnections: 20, connector: fn (): SocketInterface => $this->liveSocket());

    $named->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['2']]), $a);
    // Without an endpoint the pool cannot know whose limit it is: the conservative reading is that it is everyone's.
    $global->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['2']]));

    $this->runAsyncTest(function () use ($named, $global, $a, $b) {
        expect(poolGrants($named, $a))->toHaveCount(2)
            ->and(poolGrants($named, $b))->toHaveCount(5);
        expect(poolGrants($global, $a))->toHaveCount(2)
            ->and(poolGrants($global, $b))->toHaveCount(2);
    });
});

it('lets scans in use run when a cap falls below what is in use, takes no new connection and closes the surplus on return', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        $active = poolGrants($pool, $config);
        expect($active)->toHaveCount(4);

        $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['2']]));

        // Four are in use and two are allowed: no new connection, and no scan is cut short.
        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class);
        foreach ($active as $socket) {
            expect($socket->isClosed())->toBeFalse();
        }

        // The first two to come back are surplus and are closed; the others stay for reuse.
        $pool->release($config, $active[0]);
        $pool->release($config, $active[1]);
        expect($active[0]->isClosed())->toBeTrue()
            ->and($active[1]->isClosed())->toBeTrue()
            ->and($active[2]->isClosed())->toBeFalse()
            ->and($active[3]->isClosed())->toBeFalse();

        $pool->release($config, $active[2]);
        $pool->release($config, $active[3]);
        expect(poolGrants($pool, $config))->toHaveCount(2);
    });
});

/*
 * AP11-R02 — the server may allow more than it said before (a later OPTIONS answer), and then the calls that wait
 * for a connection are served at once, not when their wait runs out.
 */
it('serves a waiting call when a later Max-Connections raises the cap again', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');

    // The number is the endpoint's, or - without a Config - everyone's.
    foreach ([true, false] as $forEndpoint) {
        $pool = new AmpConnectionPool(
            maxConnectionsPerHost: 2,
            connector: fn (): SocketInterface => $this->liveSocket(),
            maxWaiters: 1,
            waitTimeout: 5.0,
        );
        $advertise = static function (string $number) use ($pool, $config, $forEndpoint): void {
            $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => [$number]]), $forEndpoint ? $config : null);
        };

        $this->runAsyncTest(function () use ($pool, $config, $advertise) {
            $baseline = $this->loopTimers();
            $advertise('1');
            $held = $pool->acquire($config);
            $waiting = $this->acquireLater($pool, $config);
            delay(0);
            expect($this->privateCount($pool, 'waiters'))->toBe(1);

            $advertise('2'); // the server allows two after all, which is what the pool allows itself
            $second = $waiting->await(new TimeoutCancellation(1.0));

            expect($second)->not->toBe($held)
                ->and($second->isClosed())->toBeFalse()
                ->and($held->isClosed())->toBeFalse()
                ->and($this->privateCount($pool, 'waiters'))->toBe(0)
                ->and($this->privateCount($pool, 'connecting'))->toBe(0);

            // Two connections, not three: the raised cap is the pool's own.
            expect(fn () => $pool->acquire($config, new TimeoutCancellation(0.05)))->toThrow(CancelledException::class);

            $pool->close();
            expect($this->loopTimers())->toBe($baseline);
        });
    }
});

/*
 * AP11-R03 — what the pool keeps about endpoints is bounded: it remembers the Max-Connections of a limited number
 * of them, says so when it takes no more (a limit that is forgotten would be a limit that is bypassed), and lets
 * go of them when it is closed.
 */
it('keeps the Max-Connections of a limited number of endpoints, refuses the rest and forgets them when it is closed', function () {
    /** @var AsyncTestCase $this */
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, maxConnections: 8, connector: fn (): SocketInterface => $this->liveSocket());
    $advertise = static function (string $number, Config $config) use ($pool): void {
        $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => [$number]]), $config);
    };

    $budget = (new ReflectionClassConstant(AmpConnectionPool::class, 'MAX_ENDPOINT_LIMITS'))->getValue();
    if (!is_int($budget)) {
        throw new LogicException('The number of endpoints a pool keeps limits for is a number.');
    }
    $first = new Config('host-0.example');
    $advertise('1', $first);

    // Endpoints that come and go, none of them ever connected to. The refusals are kept, as a caller might.
    $taken = 1;
    $refusals = [];
    for ($i = 1; $i < 2000; $i++) {
        try {
            $advertise('2', new Config("host-{$i}.example"));
            $taken++;
        } catch (IcapConnectionException $e) {
            $refusals[] = $e;
        }
    }

    expect($budget)->toBeGreaterThan(1)->toBeLessThanOrEqual(1024)
        ->and($taken)->toBe($budget)
        ->and(count($refusals))->toBe(2000 - $budget)
        ->and($this->privateCount($pool, 'serverMaxByEndpoint'))->toBe($budget)
        ->and($refusals[0]->getMessage())->toContain('takes no more');

    // What was taken is neither forgotten nor replaced, and may be changed: it is no new endpoint.
    $this->runAsyncTest(function () use ($pool, $first) {
        expect(poolGrants($pool, $first))->toHaveCount(1);
    });
    $advertise('3', $first);
    expect($this->privateCount($pool, 'serverMaxByEndpoint'))->toBe($budget);

    // Closed, the pool keeps nothing and takes nothing.
    $pool->close();
    expect($this->privateCount($pool, 'serverMaxByEndpoint'))->toBe(0);
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $advertise('2', new Config("late-{$i}.example")))->toThrow(IcapConnectionException::class, 'closed');
    }
    expect($this->privateCount($pool, 'serverMaxByEndpoint'))->toBe(0);
});

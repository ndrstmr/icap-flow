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
use Amp\DeferredFuture;
use Amp\Socket;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket as SocketInterface;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

uses(AsyncTestCase::class);

/**
 * v2.1 connection pooling. After the M3 follow-up dropped the
 * Connection: close hack, the transport can hand idle sockets back
 * to a pool keyed by host:port[:tls] and reuse them for subsequent
 * requests.
 *
 * The sockets of these tests are real socket pairs whose peer stays open
 * ({@see AsyncTestCase::liveSocket()}): a pool does not hand out a socket whose
 * peer has gone.
 */

it('reuses a released socket on the next acquire', function () {
    /** @var AsyncTestCase $this */
    $serverEnd1 = $this->liveSocket();
    $serverEnd2 = $this->liveSocket();
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
    /** @var AsyncTestCase $this */
    $serverEndA = $this->liveSocket();
    $serverEndB = $this->liveSocket();
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

    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $second = $pool->acquire($config); // pool was empty after first acquire
        expect($second)->not->toBe($first);
    });
});

it('skips a closed idle socket and connects fresh', function () {
    /** @var AsyncTestCase $this */
    $stale = $this->liveSocket();
    $fresh = $this->liveSocket();
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

    $this->runAsyncTest(function () use ($pool, $config, $fresh) {
        $first = $pool->acquire($config);
        // Simulate the server closing the connection between the two
        // requests (or a timeout intermediary dropping the socket).
        $first->close();
        $pool->release($config, $first); // pool sees it's closed → drops it
        expect($this->privateCount($pool, 'idle'))->toBe(0);

        $second = $pool->acquire($config);
        expect($second)->toBe($fresh);
    });
});

it('close() shuts down every pooled socket', function () {
    /** @var AsyncTestCase $this */
    $a = $this->liveSocket();
    $b = $this->liveSocket();
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

it('isolates idle sockets for different TLS contexts on the same host:port', function () {
    /** @var AsyncTestCase $this */
    $socketA = $this->liveSocket();
    $socketB = $this->liveSocket();
    /** @var SocketInterface[] $queue */
    $queue = [$socketA, $socketB];

    $tlsA = new \Amp\Socket\ClientTlsContext('server-a.example');
    $tlsB = new \Amp\Socket\ClientTlsContext('server-b.example');

    $configA = (new Config('icap.example'))->withTlsContext($tlsA);
    $configB = (new Config('icap.example'))->withTlsContext($tlsB);

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

    $this->runAsyncTest(function () use ($pool, $configA, $configB, $socketA, $socketB) {
        $a = $pool->acquire($configA);
        $pool->release($configA, $a);

        // Re-acquire with a DIFFERENT TLS context on the SAME host:port.
        // Must NOT return the socket that was pooled for configA.
        $b = $pool->acquire($configB);
        expect($b)->not->toBe($socketA)
            ->and($b)->toBe($socketB);
    });
});

it('isolates idle sockets per host:port', function () {
    /** @var AsyncTestCase $this */
    $h1 = $this->liveSocket();
    $h2 = $this->liveSocket();
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

/*
 * M04 — maxConnectionsPerHost was a cap on idle sockets, applied when a socket came back. Every
 * acquire() without an idle socket opened another connection: a limit of 1 let twelve through.
 * Connections in the making, in use and idle all count against the cap of the endpoint (host and
 * port, whatever the TLS context) and against the cap of the whole pool.
 */

/**
 * What a gated connector records: the calls it got, those in the making, the most connections that
 * ever existed at once (in the making plus the sockets it made that are not closed), the sockets it
 * made, the gates it holds its calls at and whether they are open for good.
 *
 * @return array{calls: int, inFlight: int, peak: int, made: list<SocketInterface>, gates: list<DeferredFuture<mixed>>, open: bool}
 */
function poolGateState(): array
{
    return ['calls' => 0, 'inFlight' => 0, 'peak' => 0, 'made' => [], 'gates' => [], 'open' => false];
}

/**
 * A connector for the pool that notes what it is asked and, if gated, holds each call at a gate until
 * the test opens it, so the test decides how many connections are in the making at a time.
 *
 * @param  array{calls: int, inFlight: int, peak: int, made: list<SocketInterface>, gates: list<DeferredFuture<mixed>>, open: bool} $state
 * @return \Closure(Config, ?Cancellation): SocketInterface
 */
function poolGatedConnector(AsyncTestCase $test, array &$state, bool $gated = true): \Closure
{
    return function (Config $config, ?Cancellation $cancellation) use ($test, &$state, $gated): SocketInterface {
        $state['calls']++;
        $state['inFlight']++;
        $open = count(array_filter($state['made'], static fn (SocketInterface $socket): bool => !$socket->isClosed()));
        $state['peak'] = max($state['peak'], $state['inFlight'] + $open);

        try {
            if ($gated && !$state['open']) {
                $gate = new DeferredFuture();
                $state['gates'][] = $gate;
                $gate->getFuture()->await($cancellation);
            }
        } finally {
            $state['inFlight']--;
        }

        return $state['made'][] = $test->liveSocket();
    };
}

/**
 * Opens the gates for good: the calls held at them go on, and later calls are not held.
 *
 * @param array{calls: int, inFlight: int, peak: int, made: list<SocketInterface>, gates: list<DeferredFuture<mixed>>, open: bool} $state
 */
function poolOpenGates(array &$state): void
{
    $state['open'] = true;
    foreach ($state['gates'] as $gate) {
        if (!$gate->isComplete()) {
            $gate->complete();
        }
    }
}

/**
 * A cancellation that counts what the code under test subscribes to it and what it gives back.
 */
final class PoolCountingCancellation implements Cancellation
{
    public int $subscriptions = 0;
    public int $active = 0;
    private int $next = 0;
    /** @var array<string, \Closure> */
    private array $callbacks = [];

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $id = 'c' . ++$this->next;
        $this->callbacks[$id] = $callback;
        $this->subscriptions++;
        $this->active++;

        return $id;
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        if (isset($this->callbacks[$id])) {
            unset($this->callbacks[$id]);
            $this->active--;
        }
    }

    #[\Override]
    public function isRequested(): bool
    {
        return false;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
    }
}

/**
 * A cancellation that does something at its second check: the pool checks it when a call begins and again after it
 * has read an idle socket, which takes a turn of the event loop, so this is the moment in between.
 */
final class PoolCheckpointCancellation implements Cancellation
{
    private int $checks = 0;

    public function __construct(private \Closure $atSecondCheck)
    {
    }

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        return 'checkpoint';
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
    }

    #[\Override]
    public function isRequested(): bool
    {
        return false;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        if (++$this->checks === 2) {
            ($this->atSecondCheck)();
        }
    }
}

it('never has more connections to an endpoint than maxConnectionsPerHost, those in the making included', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: poolGatedConnector($this, $state));

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        // Six calls at once: the connector holds two of them at its gate, the others find no room.
        $calls = [];
        for ($i = 0; $i < 6; $i++) {
            $calls[] = $this->acquireLater($pool, $config);
        }
        delay(0);
        expect($state['calls'])->toBe(2);

        poolOpenGates($state);
        $sockets = [];
        $refused = 0;
        foreach ($calls as $call) {
            try {
                $sockets[] = $call->await();
            } catch (IcapConnectionException) {
                $refused++;
            }
        }
        expect(count($sockets))->toBe(2)
            ->and($refused)->toBe(4)
            ->and($state['peak'])->toBe(2);

        // Sockets that are out count, and so do idle ones.
        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class);
        foreach ($sockets as $socket) {
            $pool->release($config, $socket);
        }
        $again = $pool->acquire($config);
        expect($state['calls'])->toBe(2)
            ->and($state['peak'])->toBe(2);
        $pool->release($config, $again);
    });
});

it('counts the connections of every TLS context against the cap of the endpoint', function () {
    /** @var AsyncTestCase $this */
    $a = (new Config('icap.example'))->withTlsContext(new ClientTlsContext('a.example'));
    $b = (new Config('icap.example'))->withTlsContext(new ClientTlsContext('b.example'));
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $a, $b) {
        $first = $pool->acquire($a);
        $second = $pool->acquire($a);

        // Another TLS context is another bucket, not another allowance.
        expect(fn () => $pool->acquire($b))->toThrow(IcapConnectionException::class);

        // An idle socket of the other context gives way; one in use does not.
        $pool->release($a, $first);
        $third = $pool->acquire($b);
        expect($first->isClosed())->toBeTrue()
            ->and($second->isClosed())->toBeFalse()
            ->and($third->isClosed())->toBeFalse();
    });
});

it('counts the connections of all endpoints against maxConnections, which is the cap of an endpoint unless set', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $c = new Config('host-c');
    $explicit = new AmpConnectionPool(maxConnectionsPerHost: 2, maxConnections: 3, connector: fn (): SocketInterface => $this->liveSocket());
    $default = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($explicit, $default, $a, $b, $c) {
        // A socket nobody holds any more is closed by PHP and its room is free: the test keeps what it is lent.
        $held = [$explicit->acquire($a), $explicit->acquire($a), $explicit->acquire($b)];
        // Host B has room for one more, the pool as a whole has not.
        expect(fn () => $explicit->acquire($b))->toThrow(IcapConnectionException::class);

        $held[] = $default->acquire($a);
        $held[] = $default->acquire($b);
        expect(fn () => $default->acquire($c))->toThrow(IcapConnectionException::class);
        expect(count($held))->toBe(5);
    });
});

it('closes an idle socket of another endpoint to make room and never one in use', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $c = new Config('host-c');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, maxConnections: 2, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $a, $b, $c) {
        $socketA = $pool->acquire($a);
        $socketB = $pool->acquire($b);

        expect(fn () => $pool->acquire($c))->toThrow(IcapConnectionException::class);
        expect($socketA->isClosed())->toBeFalse()
            ->and($socketB->isClosed())->toBeFalse();

        $pool->release($b, $socketB);
        $socketC = $pool->acquire($c);
        expect($socketB->isClosed())->toBeTrue()
            ->and($socketA->isClosed())->toBeFalse()
            ->and($socketC->isClosed())->toBeFalse();
    });
});

it('frees the room of a failed connect, a failed TLS handshake, a cancelled connect and a socket its holder closed, once each', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $connect = new ArrayObject(['mode' => 'fail']);
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: function (Config $config, ?Cancellation $cancellation) use ($connect): SocketInterface {
            if ($connect['mode'] === 'fail') {
                throw new IcapConnectionException('connect refused');
            }
            if ($connect['mode'] === 'tls') {
                throw new Socket\TlsException('TLS negotiation failed');
            }
            if ($connect['mode'] === 'hang') {
                (new DeferredFuture())->getFuture()->await($cancellation); // ends with the cancellation only
            }

            return $this->liveSocket();
        },
    );

    $this->runAsyncTest(function () use ($pool, $config, $connect) {
        // Whatever happened before, there is exactly one room: one call gets it, the next finds none.
        $oneRoom = static function () use ($pool, $config, $connect): SocketInterface {
            $connect['mode'] = 'ok';
            $held = $pool->acquire($config);
            expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class);

            return $held;
        };

        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class, 'connect refused');
        $held = $oneRoom();
        $held->close();

        $connect['mode'] = 'tls';
        expect(fn () => $pool->acquire($config))->toThrow(Socket\TlsException::class);
        $held = $oneRoom();

        // The holder closes the socket itself, without giving it back; then a connect is cancelled.
        $held->close();
        $connect['mode'] = 'hang';
        $cancel = new DeferredCancellation();
        $call = $this->acquireLater($pool, $config, $cancel->getCancellation());
        delay(0);
        $cancel->cancel();
        expect(fn () => $call->await())->toThrow(CancelledException::class);

        $held = $oneRoom();
        $held->close();
        $held = $oneRoom();

        // Given back, the socket stays counted and is the one that is handed out next.
        $pool->release($config, $held);
        expect($oneRoom())->toBe($held);
    });
});

it('asks the connector nothing for a call cancelled before it began and closes what a connector delivers after the caller gave up', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $asked = 0;
    $late = new DeferredCancellation();
    $delivered = null;
    $pool = new AmpConnectionPool(connector: function () use (&$asked, $late, &$delivered): SocketInterface {
        $asked++;
        $late->cancel(); // the caller gives up while the connector works, and the connector does not notice

        return $delivered = $this->liveSocket();
    });

    $this->runAsyncTest(function () use ($pool, $config, $late, &$asked, &$delivered) {
        $before = new DeferredCancellation();
        $before->cancel();
        expect(fn () => $pool->acquire($config, $before->getCancellation()))->toThrow(CancelledException::class);
        expect($asked)->toBe(0);

        expect(fn () => $pool->acquire($config, $late->getCancellation()))->toThrow(CancelledException::class);
        expect($asked)->toBe(1);
        expect($delivered?->isClosed())->toBeTrue();
        expect($this->privateCount($pool, 'idle'))->toBe(0);
    });
});

it('refuses a call at once when the pool is full and no queue is configured', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 1, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);

        $start = hrtime(true);
        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class, 'no queue');
        expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.5);
        expect($held->isClosed())->toBeFalse();
    });
});

it('serves calls that wait for a connection first come, first served', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: poolGatedConnector($this, $state, gated: false),
        maxWaiters: 3,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $held = $pool->acquire($config);
        $order = [];
        $first = $this->acquireLater($pool, $config);
        $first->finally(static function () use (&$order): void {
            $order[] = 'first';
        })->ignore();
        delay(0);
        $second = $this->acquireLater($pool, $config);
        $second->finally(static function () use (&$order): void {
            $order[] = 'second';
        })->ignore();
        delay(0);
        expect($order)->toBe([]);

        // The one connection is passed on, in the order the calls came.
        $pool->release($config, $held);
        $one = $first->await();
        delay(0.02);
        expect($order)->toBe(['first']);

        $pool->release($config, $one);
        $two = $second->await();
        expect($order)->toBe(['first', 'second'])
            ->and($state['calls'])->toBe(1)
            ->and($one)->toBe($held)
            ->and($two)->toBe($held);
    });
});

it('refuses a call beyond the queue limit', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);

        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class, 'is full');

        $pool->release($config, $held);
        expect($waiting->await())->toBe($held);
    });
});

it('ends a wait with an IcapTimeoutException when the wait timeout passes, and leaves no waiting call behind', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 0.05,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);

        $start = hrtime(true);
        expect(fn () => $pool->acquire($config))->toThrow(IcapTimeoutException::class);
        $elapsed = (hrtime(true) - $start) / 1e9;
        expect($elapsed)->toBeGreaterThanOrEqual(0.04)->toBeLessThan(2.0);
        expect($this->privateCount($pool, 'waiters'))->toBe(0);

        // The room was not lost on the way: the one connection is still the pool's to give.
        $pool->release($config, $held);
        expect($pool->acquire($config))->toBe($held);
    });
});

it('ends a wait with the cancellation of the caller, not with a timeout', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);

        $cancel = new DeferredCancellation();
        $call = $this->acquireLater($pool, $config, $cancel->getCancellation());
        delay(0.01);
        $cancel->cancel();

        expect(fn () => $call->await())->toThrow(CancelledException::class);
        expect($this->privateCount($pool, 'waiters'))->toBe(0);
        expect($held->isClosed())->toBeFalse();
    });
});

it('gives back the connection a waiting call was granted when the caller cancelled in the same moment', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: poolGatedConnector($this, $state, gated: false),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $held = $pool->acquire($config);
        $cancel = new DeferredCancellation();
        $call = $this->acquireLater($pool, $config, $cancel->getCancellation());
        delay(0);

        $pool->release($config, $held); // hands the connection to the waiting call, which has not run yet
        $cancel->cancel();               // and the caller gives up before it does

        expect(fn () => $call->await())->toThrow(CancelledException::class);
        // The room is free again: the next call is served and the pool never held more than one connection.
        $again = $pool->acquire($config);
        expect($again->isClosed())->toBeFalse()
            ->and($state['peak'])->toBe(1);
    });
});

/*
 * AP11-R01 — the wait of a queued call ends at its deadline on a monotonic clock, not when the timer gets to run.
 * Something that holds the event loop up (here: usleep) lets the deadline pass without the timer having said so;
 * what the pool grants after that is not for the call that has given up.
 */
it('does not hand a socket to a waiting call whose wait has run out, although its timer has not run yet', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 0.03,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $baseline = $this->loopTimers();
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);
        expect($this->privateCount($pool, 'waiters'))->toBe(1);

        // The event loop is held up for four times the wait: the timer has not run, and the wait is over.
        usleep(120_000);
        $pool->release($config, $held);

        expect(fn () => $waiting->await(new TimeoutCancellation(2.0)))->toThrow(IcapTimeoutException::class);

        // The socket was not given to the call that gave up: it is idle, and the next call has it.
        expect($held->isClosed())->toBeFalse()
            ->and($this->privateCount($pool, 'waiters'))->toBe(0);
        $next = $pool->acquire($config);
        expect($next)->toBe($held);

        $pool->close();
        expect($this->loopTimers())->toBe($baseline);
    });
});

it('opens no connection for a waiting call whose wait has run out, and leaves the room to the next call', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: poolGatedConnector($this, $state, gated: false),
        maxWaiters: 1,
        waitTimeout: 0.03,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $baseline = $this->loopTimers();
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);
        expect($this->privateCount($pool, 'waiters'))->toBe(1);

        usleep(120_000);
        $held->close(); // the room is free ...
        $pool->release($config, $this->liveSocket()); // ... and a stranger's socket given back makes the pool look

        expect(fn () => $waiting->await(new TimeoutCancellation(2.0)))->toThrow(IcapTimeoutException::class);

        // No connection was begun for the call that gave up, and the room was not lost on the way.
        expect($state['calls'])->toBe(1)
            ->and($this->privateCount($pool, 'connecting'))->toBe(0)
            ->and($this->privateCount($pool, 'waiters'))->toBe(0);
        $next = $pool->acquire($config);
        expect($next->isClosed())->toBeFalse()
            ->and($state['calls'])->toBe(2)
            ->and($state['peak'])->toBe(1);

        $pool->close();
        expect($this->loopTimers())->toBe($baseline);
    });
});

it('takes back what a waiting call was granted when the call gets to take it only after its wait has run out', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: poolGatedConnector($this, $state, gated: false),
        maxWaiters: 1,
        waitTimeout: 0.03,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $baseline = $this->loopTimers();
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);

        // Granted within the wait; but the event loop is held up before the call can take it over.
        $pool->release($config, $held);
        usleep(120_000);

        expect(fn () => $waiting->await(new TimeoutCancellation(2.0)))->toThrow(IcapTimeoutException::class);

        // What it was granted went back once: no phantom connection holds the room, and the next call is served.
        expect($this->privateCount($pool, 'waiters'))->toBe(0)
            ->and($this->privateCount($pool, 'connecting'))->toBe(0);
        $next = $pool->acquire($config, new TimeoutCancellation(2.0));
        expect($next->isClosed())->toBeFalse()
            ->and($state['peak'])->toBe(1);

        $pool->close();
        expect($this->loopTimers())->toBe($baseline);
    });
});

it('ends waiting calls when the pool is closed', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 2,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $calls = [
            $this->acquireLater($pool, $config),
            $this->acquireLater($pool, $config),
        ];
        delay(0);

        $closedAt = hrtime(true);
        $pool->close();

        foreach ($calls as $call) {
            expect(fn () => $call->await())->toThrow(IcapConnectionException::class, 'closed');
        }
        // At once, not when the wait of 5 seconds runs out.
        expect((hrtime(true) - $closedAt) / 1e9)->toBeLessThan(1.0);
        expect($this->privateCount($pool, 'waiters'))->toBe(0);
        expect($held->isClosed())->toBeFalse(); // in use: left to its holder
    });
});

it('removes its timer and its subscription on every way a wait or a connect can end', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 0.03,
    );
    $failing = new AmpConnectionPool(connector: static fn (): never => throw new IcapConnectionException('connect refused'));

    $this->runAsyncTest(function () use ($pool, $failing, $config) {
        $baseline = $this->loopTimers();
        $watched = new PoolCountingCancellation();
        $held = $pool->acquire($config, $watched);

        // A wait that times out, one that is served, one that the close of the pool ends, a connect that fails.
        expect(fn () => $pool->acquire($config, $watched))->toThrow(IcapTimeoutException::class);

        $served = $this->acquireLater($pool, $config, $watched);
        delay(0);
        $pool->release($config, $held);
        $inUse = $served->await();

        $closed = $this->acquireLater($pool, $config, $watched);
        delay(0);
        $pool->close();
        expect(fn () => $closed->await())->toThrow(IcapConnectionException::class);

        expect(fn () => $failing->acquire($config, $watched))->toThrow(IcapConnectionException::class);

        expect($watched->subscriptions)->toBeGreaterThan(0)
            ->and($watched->active)->toBe(0)
            ->and($this->loopTimers())->toBe($baseline)
            ->and($inUse->isClosed())->toBeFalse();
    });
});

it('pools a socket once however often it is released', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        $socket = $pool->acquire($config);
        $pool->release($config, $socket);
        $pool->release($config, $socket);

        $one = $pool->acquire($config);
        $two = $pool->acquire($config);

        expect($one)->toBe($socket)
            ->and($two)->not->toBe($socket);
    });
});

it('closes a socket that is released for another endpoint or another TLS context than it was acquired for', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $tls1 = (new Config('host-a'))->withTlsContext(new ClientTlsContext('a.example'));
    $tls2 = (new Config('host-a'))->withTlsContext(new ClientTlsContext('a.example'));
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $a, $b, $tls1, $tls2) {
        $socket = $pool->acquire($a);
        $pool->release($b, $socket);
        expect($socket->isClosed())->toBeTrue();
        expect($pool->acquire($b))->not->toBe($socket);

        // The same endpoint under another trust context is no better a place for the socket.
        $secured = $pool->acquire($tls1);
        $pool->release($tls2, $secured);
        expect($secured->isClosed())->toBeTrue();
        expect($pool->acquire($tls2))->not->toBe($secured);
    });
});

it('does not take a socket it never issued', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $stranger = $this->liveSocket();
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config, $stranger) {
        $pool->release($config, $stranger);

        expect($stranger->isClosed())->toBeTrue();
        expect($pool->acquire($config))->not->toBe($stranger);
        expect($this->privateCount($pool, 'idle'))->toBe(0);
    });
});

it('close() closes idle sockets and late connects and leaves sockets in use to their holders, who close them on return', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $elsewhere = new Config('host-b');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: poolGatedConnector($this, $state));

    $this->runAsyncTest(function () use ($pool, $config, $elsewhere, &$state) {
        $first = $this->acquireLater($pool, $config);
        $second = $this->acquireLater($pool, $config);
        delay(0);
        poolOpenGates($state);
        $idle = $first->await();
        $leased = $second->await();
        $pool->release($config, $idle);

        // A connect to another endpoint is still in the making when the pool is closed.
        $state['open'] = false;
        $late = $this->acquireLater($pool, $elsewhere);
        delay(0);
        $pool->close();
        poolOpenGates($state);

        expect(fn () => $late->await())->toThrow(IcapConnectionException::class, 'closed');
        expect($state['made'][2]->isClosed())->toBeTrue()
            ->and($idle->isClosed())->toBeTrue()
            ->and($leased->isClosed())->toBeFalse();

        $pool->release($config, $leased);
        expect($leased->isClosed())->toBeTrue();

        // A closed pool connects to nobody.
        $asked = $state['calls'];
        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class, 'closed');
        expect($state['calls'])->toBe($asked);
    });
});

it('serves a waiting call when the holder closes its socket instead of giving it back, or lets go of it', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);

        $held->close(); // no release(): the room is free all the same, and the waiting call is told
        $second = $waiting->await(new TimeoutCancellation(1.0));
        expect($second)->not->toBe($held)->and($second->isClosed())->toBeFalse();

        // A socket that its holder drops without closing it is closed by PHP; its room comes back as well.
        $waiting = $this->acquireLater($pool, $config);
        delay(0);
        unset($second);
        gc_collect_cycles();
        $third = $waiting->await(new TimeoutCancellation(1.0));
        expect($third->isClosed())->toBeFalse();
    });
});

/*
 * AP11-R02 — a socket whose peer hung up is read to its end by the holder, which closes the reading end of the
 * socket; the writing end is closed later, by the holder, and nothing tells the pool about that. The room of such a
 * connection has to reach a waiting call all the same (generous wait: a call served only by its timeout fails).
 */
it('serves a waiting call when the peer hung up and the holder gave up its socket after reading the end of the stream', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');

    // The call begins to wait after the holder read the end of the stream, or before it.
    foreach ([false, true] as $waitsFirst) {
        $peers = [];
        $asked = 0;
        $pool = new AmpConnectionPool(
            maxConnectionsPerHost: 1,
            connector: function () use (&$peers, &$asked): SocketInterface {
                $asked++;
                [$peer, $end] = $this->socketPair();
                $peers[] = $peer;

                return $end;
            },
            maxWaiters: 1,
            waitTimeout: 5.0,
        );

        $this->runAsyncTest(function () use ($pool, $config, &$peers, &$asked, $waitsFirst) {
            $baseline = $this->loopTimers();
            $held = $pool->acquire($config);
            $waiting = null;
            if ($waitsFirst) {
                $waiting = $this->acquireLater($pool, $config);
                delay(0);
            }

            $peers[0]->close(); // the server hangs up
            expect($held->read())->toBeNull(); // the holder reads the end of the stream
            delay(0.01); // the pool has been told that the socket closed: its reading end did

            if ($waiting === null) {
                $waiting = $this->acquireLater($pool, $config);
                delay(0);
            }
            $held->close(); // the holder is done with it, as a failed scan is

            $second = $waiting->await(new TimeoutCancellation(1.0));
            expect($second)->not->toBe($held)
                ->and($second->isClosed())->toBeFalse()
                ->and($asked)->toBe(2)
                ->and($this->privateCount($pool, 'waiters'))->toBe(0)
                ->and($this->privateCount($pool, 'connecting'))->toBe(0);

            // The room is the second connection's alone: a third call finds the pool full.
            expect(fn () => $pool->acquire($config, new TimeoutCancellation(0.05)))->toThrow(CancelledException::class);

            $pool->close();
            expect($this->loopTimers())->toBe($baseline);
        });
    }
});

it('gives the room of an idle socket that turns out dead to the call it was handed to, not to one that waits behind', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $peers = [];
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: function () use (&$peers): SocketInterface {
            [$peer, $end] = $this->socketPair();
            $peers[] = $peer;

            return $end;
        },
        maxWaiters: 2,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$peers) {
        $held = $pool->acquire($config);
        $first = $this->acquireLater($pool, $config);
        delay(0);
        $second = $this->acquireLater($pool, $config);
        delay(0);

        // The server drops the connection while it is in use; it is given back and goes to the first call in line.
        $peers[0]->close();
        $pool->release($config, $held);
        $served = $first->await(new TimeoutCancellation(1.0));
        delay(0.02);

        expect($served)->not->toBe($held)
            ->and($held->isClosed())->toBeTrue()
            ->and(count($peers))->toBe(2) // one new connection, for the first call
            ->and($second->isComplete())->toBeFalse();

        $pool->release($config, $served);
        expect($second->await(new TimeoutCancellation(1.0)))->toBe($served);
    });
});

it('hands two calls that arrive together one idle socket and one new connection, never the same socket twice', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: poolGatedConnector($this, $state, gated: false));

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $idle = $pool->acquire($config);
        $pool->release($config, $idle);

        // The idle socket is read before it is handed out, which takes a turn of the event loop.
        $one = $this->acquireLater($pool, $config);
        $two = $this->acquireLater($pool, $config);
        $first = $one->await();
        $second = $two->await();

        expect($first)->toBe($idle)
            ->and($second)->not->toBe($idle)
            ->and($state['calls'])->toBe(2);
    });
});

it('does not turn a wrong-endpoint return into room for another endpoint', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 1, maxConnections: 2, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $a, $b) {
        $socketA = $pool->acquire($a);
        $pool->release($b, $socketA); // closed, and the room of host A is the one that is free now
        expect($socketA->isClosed())->toBeTrue();

        $held = [$pool->acquire($a), $pool->acquire($b)];
        expect(fn () => $pool->acquire($a))->toThrow(IcapConnectionException::class);
        expect(fn () => $pool->acquire($b))->toThrow(IcapConnectionException::class);
        expect(count($held))->toBe(2);
    });
});

it('does not let a call that arrives pass the calls that wait, while room is free and they have not been told', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 2,
        waitTimeout: 0.3,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);

        // The holder closes its socket: the room is free, and the pool hears of it one turn of the loop later.
        // A call that arrives in that moment queues behind the one that waits, which gets the room.
        $held->close();
        expect(fn () => $pool->acquire($config))->toThrow(IcapTimeoutException::class);

        expect($waiting->await(new TimeoutCancellation(1.0))->isClosed())->toBeFalse();
    });
});

it('hands out the most recently returned idle socket first', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        $a = $pool->acquire($config);
        $b = $pool->acquire($config);
        $c = $pool->acquire($config);
        $pool->release($config, $a);
        $pool->release($config, $b);
        $pool->release($config, $c);

        expect($pool->acquire($config))->toBe($c)
            ->and($pool->acquire($config))->toBe($b)
            ->and($pool->acquire($config))->toBe($a);
    });
});

it('hands a waiting call one room, however many sockets are closed in the same breath', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 0.3,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $one = $pool->acquire($config);
        $two = $pool->acquire($config);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);

        // Two rooms come free at once, and the pool is told twice before the waiting call has run.
        $one->close();
        $two->close();
        $served = $waiting->await(new TimeoutCancellation(1.0));

        // The call got one of them; the other is still there, not lost to a second grant for the same call.
        $third = $pool->acquire($config);
        expect($served->isClosed())->toBeFalse()
            ->and($third->isClosed())->toBeFalse()
            ->and($this->privateCount($pool, 'connecting'))->toBe(0);
    });
});

it('serves the next waiting call when a granted room goes back because its call was cancelled', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 2,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $cancel = new DeferredCancellation();
        $cancelled = $this->acquireLater($pool, $config, $cancel->getCancellation());
        delay(0);
        $next = $this->acquireLater($pool, $config);
        delay(0);

        // The room goes to the first waiting call (a stranger's socket given back makes the pool look), which
        // gives up before it has run: the room is the next call's.
        $held->close();
        $pool->release($config, $this->liveSocket());
        $cancel->cancel();

        expect(fn () => $cancelled->await())->toThrow(CancelledException::class);
        expect($next->await(new TimeoutCancellation(1.0))->isClosed())->toBeFalse();
    });
});

it('closes the oldest idle socket to make room, of the endpoint or of the pool', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now;
    };
    $a = new Config('host-a');
    $b = new Config('host-b');
    $c = new Config('host-c');
    $x = (new Config('host-d'))->withTlsContext(new ClientTlsContext('x.example'));
    $y = (new Config('host-d'))->withTlsContext(new ClientTlsContext('y.example'));
    $z = (new Config('host-d'))->withTlsContext(new ClientTlsContext('z.example'));
    $ofPool = new AmpConnectionPool(maxConnectionsPerHost: 2, maxConnections: 2, connector: fn (): SocketInterface => $this->liveSocket(), clock: $clock);
    $elsewhere = new Config('host-e');
    $ofEndpoint = new AmpConnectionPool(maxConnectionsPerHost: 2, maxConnections: 10, connector: fn (): SocketInterface => $this->liveSocket(), clock: $clock);

    $this->runAsyncTest(function () use ($ofPool, $ofEndpoint, $a, $b, $c, $x, $y, $z, $elsewhere, &$now) {
        // Of the pool: two endpoints with an idle socket each fill it, and a third endpoint wants a connection.
        $first = $ofPool->acquire($a);
        $second = $ofPool->acquire($b);
        $ofPool->release($a, $first);
        $now = 5.0;
        $ofPool->release($b, $second);
        $third = $ofPool->acquire($c);
        expect($first->isClosed())->toBeTrue()
            ->and($second->isClosed())->toBeFalse()
            ->and($third->isClosed())->toBeFalse();

        // Of the endpoint: two idle sockets for two TLS contexts of one host, and a third context wants a connection.
        // An idle socket of another endpoint is older than both and is none of the host's business.
        $now = 9.0;
        $stranger = $ofEndpoint->acquire($elsewhere);
        $ofEndpoint->release($elsewhere, $stranger);
        $now = 10.0;
        $one = $ofEndpoint->acquire($x);
        $two = $ofEndpoint->acquire($y);
        $ofEndpoint->release($x, $one);
        $now = 15.0;
        $ofEndpoint->release($y, $two);
        $other = $ofEndpoint->acquire($z);
        expect($one->isClosed())->toBeTrue()
            ->and($two->isClosed())->toBeFalse()
            ->and($stranger->isClosed())->toBeFalse()
            ->and($other->isClosed())->toBeFalse();
    });
});

it('does not hand out an idle socket to a caller that gave up while it was read, or to one whose pool was closed meanwhile', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        // The pool asks the cancellation when the call begins and again when the idle socket has been read.
        $idle = $pool->acquire($config);
        $pool->release($config, $idle);
        $cancelled = new PoolCheckpointCancellation(static function (): never {
            throw new CancelledException();
        });
        expect(fn () => $pool->acquire($config, $cancelled))->toThrow(CancelledException::class);
        expect($idle->isClosed())->toBeTrue();

        $next = $pool->acquire($config);
        $pool->release($config, $next);
        $closing = new PoolCheckpointCancellation(static function () use ($pool): void {
            $pool->close();
        });
        expect(fn () => $pool->acquire($config, $closing))->toThrow(IcapConnectionException::class, 'closed');
        expect($next->isClosed())->toBeTrue();
    });
});

it('leaves alone a socket that the connector hands out twice, and does not keep the room it reserved', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $socket = $this->liveSocket();
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: fn (): SocketInterface => $socket);

    $this->runAsyncTest(function () use ($pool, $config, $socket) {
        $first = $pool->acquire($config);
        expect($first)->toBe($socket);

        expect(fn () => $pool->acquire($config))->toThrow(IcapConnectionException::class, 'in use');
        expect($socket->isClosed())->toBeFalse() // its holder is still using it
            ->and($this->privateCount($pool, 'connecting'))->toBe(0);
    });
});

it('does not hand out an idle socket that somebody is still reading', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $reading = async(static fn (): ?string => $first->read()); // a read that nobody ended
        delay(0);
        $pool->release($config, $first);

        $second = $pool->acquire($config);

        expect($second)->not->toBe($first)
            ->and($first->isClosed())->toBeTrue();
        expect($reading->await())->toBeNull();
    });
});

it('serves a call for another endpoint at once although calls wait for the first', function () {
    /** @var AsyncTestCase $this */
    $a = new Config('host-a');
    $b = new Config('host-b');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        maxConnections: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 2,
        waitTimeout: 0.3,
    );

    $this->runAsyncTest(function () use ($pool, $a, $b) {
        $held = $pool->acquire($a);
        $waiting = $this->acquireLater($pool, $a);
        delay(0);

        // Host B has room; the call for it is behind the one that waits for host A, but is served in the same breath.
        $start = hrtime(true);
        $other = $pool->acquire($b);
        expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.2)
            ->and($other->isClosed())->toBeFalse()
            ->and($waiting->isComplete())->toBeFalse();

        $pool->release($a, $held);
        expect($waiting->await(new TimeoutCancellation(1.0)))->toBe($held);
    });
});

it('serves a waiting call when the connect that held the room fails', function () {
    /** @var AsyncTestCase $this */
    $state = poolGateState();
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: poolGatedConnector($this, $state),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config, &$state) {
        $connecting = $this->acquireLater($pool, $config);
        delay(0);
        $waiting = $this->acquireLater($pool, $config);
        delay(0);
        expect($state['calls'])->toBe(1);

        $state['gates'][0]->error(new IcapConnectionException('connect refused'));
        expect(fn () => $connecting->await())->toThrow(IcapConnectionException::class, 'connect refused');
        delay(0);

        // The room went to the waiting call, which is connecting now.
        expect($state['calls'])->toBe(2);
        poolOpenGates($state);
        expect($waiting->await(new TimeoutCancellation(1.0))->isClosed())->toBeFalse();
    });
});

it('gives back the room a waiting call was granted when the caller cancelled in the same moment', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxWaiters: 1,
        waitTimeout: 5.0,
    );

    $this->runAsyncTest(function () use ($pool, $config) {
        $held = $pool->acquire($config);
        $cancel = new DeferredCancellation();
        $call = $this->acquireLater($pool, $config, $cancel->getCancellation());
        delay(0);

        // The room is free and handed to the waiting call at once (a stranger's socket given back makes the pool
        // look); the caller gives up before the call has run.
        $held->close();
        $pool->release($config, $this->liveSocket());
        $cancel->cancel();

        expect(fn () => $call->await())->toThrow(CancelledException::class);
        expect($this->privateCount($pool, 'connecting'))->toBe(0);
        expect($pool->acquire($config)->isClosed())->toBeFalse();
    });
});

it('opens no connection beyond a cap that fell while an idle socket was being read', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $peers = [];
    $asked = 0;
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 2, connector: function () use (&$peers, &$asked): SocketInterface {
        $asked++;
        [$peer, $end] = $this->socketPair();
        $peers[] = $peer;

        return $end;
    });

    $this->runAsyncTest(function () use ($pool, $config, &$peers, &$asked) {
        $dead = $pool->acquire($config);
        $inUse = $pool->acquire($config);
        $pool->release($config, $dead);
        $peers[0]->close(); // the server went away; the pool has not noticed

        // While the dead socket is read, the server's Max-Connections lowers the cap to 1.
        $lowering = new PoolCheckpointCancellation(static function () use ($pool, $config): void {
            $pool->tuneFromOptions(new IcapResponse(200, ['Max-Connections' => ['1']]), $config);
        });
        expect(fn () => $pool->acquire($config, $lowering))->toThrow(IcapConnectionException::class);
        expect($asked)->toBe(2)->and($inUse->isClosed())->toBeFalse();
    });
});

it('counts host names that differ only in case as one endpoint', function () {
    /** @var AsyncTestCase $this */
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 1, maxConnections: 4, connector: fn (): SocketInterface => $this->liveSocket());

    $this->runAsyncTest(function () use ($pool) {
        $held = $pool->acquire(new Config('Icap.Example'));

        expect(fn () => $pool->acquire(new Config('icap.example')))->toThrow(IcapConnectionException::class);
        expect($held->isClosed())->toBeFalse();
    });
});

/**
 * What the connector of a storm test has made: the sockets (weakly, so that a socket nobody holds can be
 * collected), their peers, and how many connections were asked for, are in the making and have existed at once.
 */
final class PoolStormSockets
{
    /** @var array<int, WeakReference<SocketInterface>> */
    public array $made = [];
    /** @var array<int, SocketInterface> */
    public array $peers = [];
    public int $asked = 0;
    public int $inFlight = 0;
    public int $peak = 0;

    /**
     * How many of the sockets are alive and not closed. The peers of the others are let go.
     */
    public function alive(): int
    {
        $count = 0;
        foreach ($this->made as $i => $reference) {
            $socket = $reference->get();
            if ($socket !== null && !$socket->isClosed()) {
                $count++;
            } else {
                $this->peers[$i]->close();
                unset($this->made[$i], $this->peers[$i]);
            }
        }

        return $count;
    }
}

/*
 * G6 — under load the pool never has more connections than it allows, at any moment: a storm of calls that are
 * served, refused, time out, give up, close their socket, give it back or forget it leaves no connection, no
 * waiting call and no room reserved behind.
 */
it('holds its limits at every moment under a storm of calls, failures and cancellations, and leaves nothing behind', function () {
    /** @var AsyncTestCase $this */
    $hosts = [new Config('host-a'), new Config('host-b'), new Config('host-c')];

    // case => [queue length, wait timeout, how many calls at least are served]
    foreach (['with a queue' => [30, 0.05, 10], 'without a queue' => [0, null, 1]] as $case => [$queue, $waitTimeout, $atLeast]) {
        mt_srand(20261022);
        $sockets = new PoolStormSockets();
        $pool = new AmpConnectionPool(
            maxConnectionsPerHost: 3,
            connector: static function (Config $config, ?Cancellation $cancellation) use ($sockets): SocketInterface {
                $sockets->asked++;
                $sockets->inFlight++;
                $sockets->peak = max($sockets->peak, $sockets->inFlight + $sockets->alive());
                try {
                    delay(mt_rand(0, 3) / 1000, true, $cancellation);
                    if ($sockets->asked % 7 === 0) {
                        throw new IcapConnectionException('connect refused');
                    }
                } finally {
                    $sockets->inFlight--;
                }
                [$peer, $socket] = Socket\createSocketPair();
                $sockets->made[] = WeakReference::create($socket);
                $sockets->peers[array_key_last($sockets->made)] = $peer;

                return $socket;
            },
            maxConnections: 5,
            maxWaiters: $queue,
            waitTimeout: $waitTimeout,
        );

        $this->runAsyncTest(function () use ($pool, $hosts, $sockets, $case, $atLeast) {
            $outcome = ['served' => 0, 'refused' => 0, 'timed out' => 0, 'cancelled' => 0];
            $calls = [];
            for ($i = 0; $i < 120; $i++) {
                $calls[] = async(function () use ($pool, $hosts, $sockets, &$outcome): void {
                    $config = $hosts[mt_rand(0, 2)];
                    $cancel = new DeferredCancellation();
                    $timer = mt_rand(0, 6) === 0 ? EventLoop::delay(mt_rand(0, 4) / 1000, static fn () => $cancel->cancel()) : null;
                    try {
                        $socket = $pool->acquire($config, $cancel->getCancellation());
                    } catch (CancelledException) {
                        $outcome['cancelled']++;

                        return;
                    } catch (IcapTimeoutException) {
                        $outcome['timed out']++;

                        return;
                    } catch (IcapConnectionException) {
                        $outcome['refused']++;

                        return;
                    } finally {
                        if ($timer !== null) {
                            EventLoop::cancel($timer);
                        }
                    }

                    $outcome['served']++;
                    $sockets->peak = max($sockets->peak, $sockets->alive());
                    delay(mt_rand(0, 3) / 1000);
                    $end = mt_rand(0, 4);
                    if ($end === 0) {
                        $socket->close(); // the holder closes it itself
                    } elseif ($end > 1) {
                        $pool->release($config, $socket);
                    } // else: the holder forgets it, and it is closed when the call ends
                });
            }
            foreach ($calls as $call) {
                $call->await();
            }

            expect(array_sum($outcome))->toBe(120, $case);
            expect($outcome['served'])->toBeGreaterThanOrEqual($atLeast, $case);
            expect($sockets->peak)->toBeLessThanOrEqual(5, $case);

            $pool->close();
            gc_collect_cycles();
            expect($this->privateCount($pool, 'waiters'))->toBe(0, $case)
                ->and($this->privateCount($pool, 'connecting'))->toBe(0, $case)
                ->and($this->privateCount($pool, 'idle'))->toBe(0, $case)
                ->and($sockets->alive())->toBe(0, $case);
        });
    }
});

it('rejects a cap, a queue or a wait that cannot work', function () {
    $cases = [
        'maxConnections 0'             => static fn () => new AmpConnectionPool(maxConnections: 0),
        'maxConnections -1'            => static fn () => new AmpConnectionPool(maxConnections: -1),
        'maxWaiters -1'                => static fn () => new AmpConnectionPool(maxWaiters: -1),
        'a queue without a wait timeout' => static fn () => new AmpConnectionPool(maxWaiters: 2),
        'wait timeout 0'               => static fn () => new AmpConnectionPool(maxWaiters: 2, waitTimeout: 0.0),
        'wait timeout negative'        => static fn () => new AmpConnectionPool(maxWaiters: 2, waitTimeout: -1.0),
        'wait timeout NAN'             => static fn () => new AmpConnectionPool(maxWaiters: 2, waitTimeout: NAN),
        'wait timeout INF'             => static fn () => new AmpConnectionPool(maxWaiters: 2, waitTimeout: INF),
        'maxIdleSeconds NAN'           => static fn () => new AmpConnectionPool(maxIdleSeconds: NAN),
        'maxIdleSeconds INF'           => static fn () => new AmpConnectionPool(maxIdleSeconds: INF),
    ];

    foreach ($cases as $case => $make) {
        expect($make)->toThrow(InvalidArgumentException::class, '', $case);
    }
});

/**
 * B07 — the pool key must not rest on an object identity PHP may hand to
 * the next object. connectTls() connects with a clone when the peer name
 * is empty, so nothing keeps the configured context alive; once it is
 * collected, its object hash can belong to a context that trusts other
 * authorities. Real default connector, local TLS server, peer and peer
 * name verification enabled.
 */
it('does not hand a socket to another trust context after the original context was collected', function () {
    $dir = sys_get_temp_dir() . '/icap-pool-tls-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot create the TLS fixture directory.');
    }
    $cnfPath = $dir . '/openssl.cnf';
    $certPath = $dir . '/cert.pem';
    $keyPath = $dir . '/key.pem';

    $server = null;
    $pool = null;
    $socket = null;
    $peer = null;
    $second = null;
    $refused = null;

    try {
        // A self-signed certificate for 127.0.0.1 that may act as its own CA.
        file_put_contents($cnfPath, <<<'CONF'
[req]
distinguished_name=dn
req_extensions=san
prompt=no
[dn]
CN=127.0.0.1
[san]
subjectAltName=IP:127.0.0.1
basicConstraints=critical,CA:TRUE
keyUsage=critical,digitalSignature,keyEncipherment,keyCertSign
CONF);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Cannot create the TLS fixture key.');
        }
        $options = ['digest_alg' => 'sha256', 'config' => $cnfPath];
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options + ['req_extensions' => 'san']);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create the TLS fixture request.');
        }
        $cert = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'san']);
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Cannot create the TLS fixture certificate.');
        }
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);

        $server = Socket\listen('127.0.0.1:0', (new BindContext())->withTlsContext(
            (new ServerTlsContext())->withDefaultCertificate(new Certificate($certPath, $keyPath)),
        ));
        $address = $server->getAddress();
        \assert($address instanceof Socket\InternetAddress);
        $port = $address->getPort();

        $accepted = async(static function () use ($server): ?SocketInterface {
            $client = $server->accept(new TimeoutCancellation(5));
            $client?->setupTls(new TimeoutCancellation(5));

            return $client;
        });

        // The empty peer name makes connectTls() connect with a clone.
        $tls = (new ClientTlsContext())->withCaFile($certPath);
        $original = WeakReference::create($tls);
        $hash = spl_object_hash($tls);
        $config = new Config('127.0.0.1', $port, tlsContext: $tls);

        $pool = new AmpConnectionPool();
        $socket = $pool->acquire($config, new TimeoutCancellation(5));
        $peer = $accepted->await();
        $pool->release($config, $socket);

        unset($tls, $config);
        gc_collect_cycles();
        expect($original->get())->toBeNull('precondition: the configured context is collected');

        // Keep allocating until a context takes over the object hash.
        $held = [];
        $other = null;
        while (count($held) < 1000) {
            $candidate = new ClientTlsContext(); // trusts the system roots, not the fixture CA
            if (spl_object_hash($candidate) === $hash) {
                $other = $candidate;
                break;
            }
            $held[] = $candidate;
        }
        expect($other)->not->toBeNull('precondition: the object hash is handed to a new context');

        $refused = async(static function () use ($server): void {
            // A fresh connection is refused on the client side; answer it
            // without waiting for its outcome.
            $late = $server->accept(new TimeoutCancellation(5));
            try {
                $late?->setupTls(new TimeoutCancellation(5));
            } catch (Throwable) {
                // expected: the client does not trust this certificate
            } finally {
                $late?->close();
            }
        });

        // This context does not trust the certificate, so the only valid
        // outcome is a TLS failure: no socket at all, neither the idle one
        // nor a new one. Any other error fails the test.
        $failure = null;
        try {
            $second = $pool->acquire(new Config('127.0.0.1', $port, tlsContext: $other), new TimeoutCancellation(5));
        } catch (Socket\TlsException $e) {
            $failure = $e;
        }

        expect($failure)->toBeInstanceOf(Socket\TlsException::class, 'the untrusting context must be refused, not served a socket');
    } finally {
        $second?->close();
        $socket?->close();
        if ($peer instanceof SocketInterface) {
            $peer->close();
        }
        $server?->close();
        $pool?->close();
        if ($refused !== null) {
            try {
                $refused->await();
            } catch (Throwable) {
                // nothing left to clean up
            }
        }
        foreach ([$cnfPath, $certPath, $keyPath] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

/*
 * G7 — the pool serves a socket only to the TLS context it was opened for. A client certificate is
 * part of that context: two certificates never share a socket, and a rotation is a new context (or a
 * new pool), not a file that changed under an old one. Real TLS handshakes against a local server that
 * insists on a client certificate and tells which one it saw.
 */

/**
 * A CA, a server certificate for 127.0.0.1 and two client certificates (CN client-a, CN client-b), written to a
 * temporary directory.
 *
 * @return array{dir: string, ca: string, serverCert: string, serverKey: string, clients: array<string, array{cert: string, key: string}>}
 */
function poolMtlsFixture(): array
{
    $dir = sys_get_temp_dir() . '/icap-pool-mtls-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot create the mTLS fixture directory.');
    }
    $cnf = $dir . '/openssl.cnf';
    file_put_contents($cnf, <<<'CONF'
[req]
distinguished_name=dn
prompt=no
[dn]
CN=unused
[ca]
basicConstraints=critical,CA:TRUE
keyUsage=critical,keyCertSign,cRLSign
[server]
subjectAltName=IP:127.0.0.1
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
[client]
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=clientAuth
CONF);
    $options = ['digest_alg' => 'sha256', 'config' => $cnf];

    /** @return array{OpenSSLCertificate, OpenSSLAsymmetricKey, string, string} */
    $issue = static function (string $commonName, string $extensions, ?OpenSSLCertificate $caCert, ?OpenSSLAsymmetricKey $caKey) use ($options): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Cannot create a fixture key.');
        }
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create a fixture request.');
        }
        $cert = openssl_csr_sign($csr, $caCert, $caKey ?? $key, 1, $options + ['x509_extensions' => $extensions]);
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Cannot create a fixture certificate.');
        }

        return [$cert, $key, $certPem, $keyPem];
    };

    [$caCert, $caKey, $caPem] = $issue('icap-flow test CA', 'ca', null, null);
    file_put_contents($dir . '/ca.pem', $caPem);

    [, , $serverCert, $serverKey] = $issue('127.0.0.1', 'server', $caCert, $caKey);
    file_put_contents($dir . '/server.pem', $serverCert);
    file_put_contents($dir . '/server.key', $serverKey);

    $clients = [];
    foreach (['client-a', 'client-b'] as $name) {
        [, , $clientCert, $clientKey] = $issue($name, 'client', $caCert, $caKey);
        file_put_contents("{$dir}/{$name}.pem", $clientCert);
        file_put_contents("{$dir}/{$name}.key", $clientKey);
        $clients[$name] = ['cert' => "{$dir}/{$name}.pem", 'key' => "{$dir}/{$name}.key"];
    }

    return [
        'dir' => $dir,
        'ca' => $dir . '/ca.pem',
        'serverCert' => $dir . '/server.pem',
        'serverKey' => $dir . '/server.key',
        'clients' => $clients,
    ];
}

it('shares no socket between client certificates and keeps none across a rotation of the TLS context', function () {
    $fixture = poolMtlsFixture();
    $server = null;
    $serving = null;
    $pool = null;
    $replacement = null;
    $sockets = [];
    $peers = [];
    $seen = []; // the client certificate (CN) of each connection the server accepted, in order

    try {
        $server = Socket\listen('127.0.0.1:0', (new BindContext())->withTlsContext(
            (new ServerTlsContext())
                ->withDefaultCertificate(new Certificate($fixture['serverCert'], $fixture['serverKey']))
                ->withCaFile($fixture['ca'])
                ->withPeerVerification()
                ->withoutPeerNameVerification() // the client names no host: its certificate is checked against the CA only
                ->withPeerCapturing(),
        ));
        $address = $server->getAddress();
        \assert($address instanceof Socket\InternetAddress);
        $port = $address->getPort();

        $serving = async(static function () use ($server, &$seen, &$peers): void {
            while (($client = $server->accept()) !== null) {
                try {
                    $client->setupTls(new TimeoutCancellation(5));
                    $certificates = $client->getTlsInfo()?->getPeerCertificates() ?? [];
                    $parsed = $certificates === [] ? false : openssl_x509_parse($certificates[0]->toPem());
                    $subject = is_array($parsed) && is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
                    $seen[] = is_string($subject['CN'] ?? null) ? $subject['CN'] : '(none)';
                    $peers[] = $client;
                } catch (Throwable) {
                    $seen[] = '(handshake failed)';
                    $client->close();
                }
            }
        });

        // The server finishes a handshake after the client has: wait for the connections it should have accepted,
        // and a moment longer for one that it should not.
        $accepted = static function (int $count) use (&$seen): array {
            $until = hrtime(true) + 3_000_000_000;
            while (count($seen) < $count && hrtime(true) < $until) {
                delay(0.01);
            }
            delay(0.03);

            return $seen;
        };

        $contextOf = static fn (string $who): ClientTlsContext => (new ClientTlsContext())
            ->withCaFile($fixture['ca'])
            ->withCertificate(new Certificate($fixture['clients'][$who]['cert'], $fixture['clients'][$who]['key']));
        $configA = new Config('127.0.0.1', $port, tlsContext: $contextOf('client-a'));
        $configB = new Config('127.0.0.1', $port, tlsContext: $contextOf('client-b'));
        $pool = new AmpConnectionPool();

        // One certificate, one handshake: the socket comes back from the pool.
        $a1 = $sockets[] = $pool->acquire($configA, new TimeoutCancellation(5));
        $pool->release($configA, $a1);
        $a2 = $pool->acquire($configA, new TimeoutCancellation(5));
        expect($a2)->toBe($a1)->and($accepted(1))->toBe(['client-a']);
        $pool->release($configA, $a2);

        // The other certificate does not get that socket, and the server sees the other identity.
        $b1 = $sockets[] = $pool->acquire($configB, new TimeoutCancellation(5));
        expect($b1)->not->toBe($a1)->and($accepted(2))->toBe(['client-a', 'client-b']);

        // A rotation hands the pool a new context, also for the same certificate: a new connection.
        $rotated = new Config('127.0.0.1', $port, tlsContext: $contextOf('client-a'));
        $r1 = $sockets[] = $pool->acquire($rotated, new TimeoutCancellation(5));
        expect($r1)->not->toBe($a1)->and($accepted(3))->toBe(['client-a', 'client-b', 'client-a']);

        // Files that change under a context in use change nothing for the sockets already open ...
        copy($fixture['clients']['client-b']['cert'], $fixture['clients']['client-a']['cert']);
        copy($fixture['clients']['client-b']['key'], $fixture['clients']['client-a']['key']);
        $pool->release($configB, $b1);
        $a3 = $pool->acquire($configA, new TimeoutCancellation(5));
        expect($a3)->toBe($a1)->and(count($accepted(3)))->toBe(3);

        // ... a new pool with a new context is the rotation, and closing the old pool ends the old identity.
        $pool->release($configA, $a3);
        $pool->close();
        expect($a1->isClosed())->toBeTrue();
        $replacement = new AmpConnectionPool();
        $sockets[] = $replacement->acquire(new Config('127.0.0.1', $port, tlsContext: $contextOf('client-a')), new TimeoutCancellation(5));
        expect(array_slice($accepted(4), -1))->toBe(['client-b']);
    } finally {
        foreach ($sockets as $socket) {
            $socket->close();
        }
        foreach ($peers as $peer) {
            $peer->close();
        }
        $server?->close();
        $pool?->close();
        $replacement?->close();
        if ($serving !== null) {
            try {
                $serving->await();
            } catch (Throwable) {
                // nothing left to clean up
            }
        }
        foreach (glob($fixture['dir'] . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($fixture['dir']);
    }
});

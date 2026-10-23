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
use Amp\Socket\ClientTlsContext;
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

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
    /** @var AsyncTestCase $this */
    $now = 1000.0;
    $stale = $this->liveSocket();
    $fresh = $this->liveSocket();
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
    /** @var AsyncTestCase $this */
    $now = 1000.0;
    $socket = $this->liveSocket();
    $spare = $this->liveSocket();
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

it('keeps an idle socket that is exactly maxIdleSeconds old', function () {
    /** @var AsyncTestCase $this */
    $now = 1000.0;
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 30.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    $this->runAsyncTest(function () use ($pool, $config, &$now) {
        $socket = $pool->acquire($config);
        $pool->release($config, $socket);

        $now = 1030.0; // older than 30 seconds it is not yet
        expect($pool->acquire($config))->toBe($socket);
    });
});

it('defaults maxIdleSeconds to 30 when not specified', function () {
    /** @var AsyncTestCase $this */
    $now = 1000.0;
    $stale = $this->liveSocket();
    $fresh = $this->liveSocket();
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
    /** @var AsyncTestCase $this */
    $now = 1000.0;
    $stale1 = $this->liveSocket();
    $stale2 = $this->liveSocket();
    $fresh = $this->liveSocket();
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

/*
 * M05 — the idle pool aged out only the entries an acquire() happened to pop: a young socket on top of the
 * stack hid the old ones below it, other endpoints and TLS contexts were never looked at, emptied buckets
 * stayed, and the clock was the wall clock. A socket whose peer had gone away, or that had received bytes
 * nobody asked for, was handed out again because isClosed() only knows what this side did.
 */

it('ages out old sockets below a young one on top of the stack', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 1.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    $this->runAsyncTest(function () use ($pool, $config, &$now) {
        $old = $pool->acquire($config);
        $young = $pool->acquire($config);
        $pool->release($config, $old);
        $now = 2.0;
        $pool->release($config, $young);

        // The young socket is the one handed out; the old one below it is not left to rot.
        expect($pool->acquire($config))->toBe($young);
        expect($old->isClosed())->toBeTrue();
    });
});

it('ages out the idle sockets of other endpoints and TLS contexts, whatever is acquired', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $plain = new Config('host-a');
    $secured = (new Config('host-b'))->withTlsContext(new ClientTlsContext('b.example'));
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        maxConnections: 8,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 10.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    $this->runAsyncTest(function () use ($pool, $plain, $secured, &$now) {
        $a = $pool->acquire($plain);
        $b = $pool->acquire($secured);
        $pool->release($plain, $a);
        $pool->release($secured, $b);
        expect($this->privateCount($pool, 'idle'))->toBe(2);

        $now = 11.0;
        $other = $pool->acquire(new Config('host-c'));

        expect($a->isClosed())->toBeTrue()
            ->and($b->isClosed())->toBeTrue()
            ->and($other->isClosed())->toBeFalse()
            ->and($this->privateCount($pool, 'idle'))->toBe(0);
    });
});

it('drops an idle socket that was closed meanwhile, and its bucket', function () {
    /** @var AsyncTestCase $this */
    $pool = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket());
    $config = new Config('host-a');

    $this->runAsyncTest(function () use ($pool, $config) {
        $idle = $pool->acquire($config);
        $pool->release($config, $idle);
        $idle->close(); // by whoever: this side, a failed write, a library under it

        $other = $pool->acquire(new Config('host-b'));

        expect($this->privateCount($pool, 'idle'))->toBe(0)
            ->and($other->isClosed())->toBeFalse();
    });
});

it('keeps no bucket for a TLS context that has nothing idle, and no more idle sockets than it may hold', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 30.0,
        clock: function () use (&$now): float {
            return $now;
        },
    );
    $contexts = [];
    for ($i = 0; $i < 40; $i++) {
        $contexts[] = (new Config('icap.example'))->withTlsContext(new ClientTlsContext("peer-{$i}.example"));
    }

    $this->runAsyncTest(function () use ($pool, $contexts, &$now) {
        // Contexts that come and go as time passes: each leaves its socket idle and is never asked for again.
        foreach (array_slice($contexts, 0, 20) as $config) {
            $pool->release($config, $pool->acquire($config));
            $now += 31.0;
        }
        expect($this->privateCount($pool, 'idle'))->toBeLessThanOrEqual(1);

        // Contexts that come all at once: the idle sockets stay within what the pool may hold.
        foreach (array_slice($contexts, 20) as $config) {
            $pool->release($config, $pool->acquire($config));
        }
        expect($this->privateCount($pool, 'idle'))->toBeLessThanOrEqual(4)
            ->and($this->privateCount($pool, 'slots'))->toBeLessThanOrEqual(4);
    });
});

it('measures idle time on the monotonic clock by default', function () {
    $clock = (new ReflectionProperty(AmpConnectionPool::class, 'clock'))->getValue(new AmpConnectionPool());
    \assert($clock instanceof Closure);

    // The wall clock reads seconds since 1970, the monotonic one since some point of the boot.
    expect(abs((float) $clock() - hrtime(true) / 1e9))->toBeLessThan(1.0);
});

it('closes expired idle sockets by itself, without an acquire', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 0.1,
        clock: function () use (&$now): float {
            return $now;
        },
    );

    $this->runAsyncTest(function () use ($pool, $config, &$now) {
        $socket = $pool->acquire($config);
        $pool->release($config, $socket);
        expect($socket->isClosed())->toBeFalse();

        $now = 1.0;
        delay(0.3);

        expect($socket->isClosed())->toBeTrue()
            ->and($this->privateCount($pool, 'idle'))->toBe(0);

        // The timer is back when something is idle again.
        $second = $pool->acquire($config);
        $pool->release($config, $second);
        $now = 2.0;
        delay(0.3);

        expect($second->isClosed())->toBeTrue();
    });
});

it('runs its timer unreferenced, only while something is idle, and takes it away on close()', function () {
    /** @var AsyncTestCase $this */
    $now = 0.0;
    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        connector: fn (): SocketInterface => $this->liveSocket(),
        maxIdleSeconds: 0.1,
        clock: function () use (&$now): float {
            return $now;
        },
    );
    $closing = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket(), maxIdleSeconds: 60.0);

    $this->runAsyncTest(function () use ($pool, $closing, $config, &$now) {
        $baseline = $this->loopTimers();

        // Nothing idle, no timer.
        $socket = $pool->acquire($config);
        expect($this->loopTimers())->toBe($baseline);

        $pool->release($config, $socket);
        $other = $pool->acquire($config);
        $pool->release($config, $other);
        $added = array_values(array_diff($this->loopTimers(), $baseline));
        expect($added)->toHaveCount(1); // one timer for the pool, not one for every socket
        expect(EventLoop::isReferenced($added[0]))->toBeFalse();

        // Once nothing is idle any more the timer goes by itself.
        $now = 1.0;
        delay(0.3);
        expect($this->loopTimers())->toBe($baseline);

        // And close() takes it away at once.
        $closing->release($config, $closing->acquire($config));
        expect(array_diff($this->loopTimers(), $baseline))->toHaveCount(1);
        $closing->close();
        expect($this->loopTimers())->toBe($baseline);
    });
});

it('does not keep a pool alive that nobody holds any more', function () {
    /** @var AsyncTestCase $this */
    $baseline = $this->loopTimers();
    $pool = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket(), maxIdleSeconds: 60.0);
    $reference = WeakReference::create($pool);
    $config = new Config('icap.example');
    $idle = null;

    $this->runAsyncTest(function () use ($pool, $config, &$idle) {
        $idle = $pool->acquire($config);
        $pool->release($config, $idle);
    });
    expect(array_diff($this->loopTimers(), $baseline))->toHaveCount(1);

    unset($pool);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($this->loopTimers())->toBe($baseline);

    // A socket in use that the pool wants to hear about when it closes does not hold the pool either.
    $queued = new AmpConnectionPool(connector: fn (): SocketInterface => $this->liveSocket(), maxWaiters: 1, waitTimeout: 1.0);
    $queuedReference = WeakReference::create($queued);
    $leased = null;
    $this->runAsyncTest(function () use ($queued, $config, &$leased) {
        $leased = $queued->acquire($config);
    });
    unset($queued);
    gc_collect_cycles();

    expect($queuedReference->get())->toBeNull()
        ->and($leased)->toBeInstanceOf(SocketInterface::class);
});

it('does not hand out an idle socket whose peer has closed', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $peers = [];
    $pool = new AmpConnectionPool(connector: function () use (&$peers): SocketInterface {
        [$peer, $end] = $this->socketPair();
        $peers[] = $peer;

        return $end;
    });

    $this->runAsyncTest(function () use ($pool, $config, &$peers) {
        $first = $pool->acquire($config);
        $pool->release($config, $first);

        // The server closes its end while the socket sits in the pool; this side has not noticed.
        $peers[0]->close();
        expect($first->isClosed())->toBeFalse();

        $second = $pool->acquire($config);

        expect($second)->not->toBe($first)
            ->and($first->isClosed())->toBeTrue()
            ->and(count($peers))->toBe(2);
    });
});

it('does not hand out an idle socket that has received bytes nobody asked for', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $peers = [];
    $pool = new AmpConnectionPool(connector: function () use (&$peers): SocketInterface {
        [$peer, $end] = $this->socketPair();
        $peers[] = $peer;

        return $end;
    });

    $this->runAsyncTest(function () use ($pool, $config, &$peers) {
        $first = $pool->acquire($config);
        $pool->release($config, $first);

        // Bytes that came after the response, or a second response nobody is waiting for.
        $peers[0]->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
        delay(0.01);

        $second = $pool->acquire($config);

        expect($second)->not->toBe($first)
            ->and($first->isClosed())->toBeTrue();
    });
});

it('hands out a quiet idle socket with nothing pending on it, so that the next bytes are the new user\'s', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $peers = [];
    $pool = new AmpConnectionPool(connector: function () use (&$peers): SocketInterface {
        [$peer, $end] = $this->socketPair();
        $peers[] = $peer;

        return $end;
    });

    $this->runAsyncTest(function () use ($pool, $config, &$peers) {
        $first = $pool->acquire($config);
        $pool->release($config, $first);
        delay(0.01);

        $second = $pool->acquire($config);
        expect($second)->toBe($first);

        // Nothing is watching the socket: a read does not collide with an earlier one, and no byte goes astray.
        $peers[0]->write('reply');
        expect($second->read(new Amp\TimeoutCancellation(1.0)))->toBe('reply');
    });
});

it('leaves no connection, bucket, timer or stream behind when failures are kept', function () {
    /** @var AsyncTestCase $this */
    $config = new Config('icap.example');
    $connect = new ArrayObject(['mode' => 'ok']);
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: function (Config $config, ?Cancellation $cancellation) use ($connect): SocketInterface {
            if ($connect['mode'] === 'fail') {
                throw new IcapConnectionException('connect refused');
            }
            if ($connect['mode'] === 'hang') {
                (new Amp\DeferredFuture())->getFuture()->await($cancellation);
            }

            // Nothing is ever reused here, so the peer need not stay: no stream is kept alive by the test.
            [$peer, $end] = Amp\Socket\createSocketPair();
            $peer->close();

            return $end;
        },
        maxWaiters: 1,
        waitTimeout: 0.01,
    );

    $this->runAsyncTest(function () use ($pool, $config, $connect) {
        $timers = $this->loopTimers();
        $streams = count(get_resources('stream'));
        $kept = [];

        $held = [$pool->acquire($config), $pool->acquire($config)];
        for ($i = 0; $i < 25; $i++) {
            // Full pool with a short wait: refused, queued and timed out.
            try {
                $pool->acquire($config);
            } catch (IcapTimeoutException $e) {
                $kept[] = $e;
            }

            // A connect that fails, and one that is cancelled.
            $connect['mode'] = 'fail';
            array_pop($held)->close();
            try {
                $pool->acquire($config);
            } catch (IcapConnectionException $e) {
                $kept[] = $e;
            }
            $connect['mode'] = 'hang';
            $cancel = new DeferredCancellation();
            $call = $this->acquireLater($pool, $config, $cancel->getCancellation());
            delay(0);
            $cancel->cancel();
            try {
                $call->await();
            } catch (CancelledException $e) {
                $kept[] = $e;
            }
            $connect['mode'] = 'ok';
            $held[] = $pool->acquire($config);
        }

        expect($kept)->not->toBeEmpty();
        foreach ($held as $socket) {
            $socket->close();
        }
        $pool->close();

        expect($this->privateCount($pool, 'waiters'))->toBe(0)
            ->and($this->privateCount($pool, 'connecting'))->toBe(0)
            ->and($this->privateCount($pool, 'idle'))->toBe(0)
            ->and($this->privateCount($pool, 'slots'))->toBe(0)
            ->and($this->loopTimers())->toBe($timers);
        gc_collect_cycles();
        expect(count(get_resources('stream')))->toBeLessThanOrEqual($streams + 2);
    });
});

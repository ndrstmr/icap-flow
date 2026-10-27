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

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\TimeoutCancellation;
use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AmpTransportSession;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\NullConnectionPool;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\TransportSession;
use PHPUnit\Framework\Assert;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;

use function Amp\async;
use function Amp\delay;

it('implements TransportInterface', function () {
    $t = new AsyncAmpTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on invalid host', function () {
    $t = new AsyncAmpTransport();
    $config = new Config('127.0.0.1', 1); // unlikely to be open

    expect(fn () => $t->request($config, [])->await())->toThrow(IcapConnectionException::class);
});

it('connects to an IPv6 literal host, with and without a pool (N04)', function () {
    try {
        $server = Socket\listen('[::1]:0');
    } catch (Socket\SocketException) {
        Assert::markTestSkipped('IPv6 loopback is not available.');
    }
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    $config = new Config('::1', $address->getPort());

    // One connection per transport, each answered with a bare 204.
    $answering = async(function () use ($server): void {
        while (($peer = $server->accept()) !== null) {
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && ($chunk = $peer->read()) !== null) {
                $head .= $chunk;
            }
            $peer->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
        }
    });

    $pool = new AmpConnectionPool();
    try {
        foreach ([new AsyncAmpTransport(), new AsyncAmpTransport(new NullConnectionPool()), new AsyncAmpTransport($pool)] as $transport) {
            $response = $transport->request($config, ["OPTIONS icap://[::1]/scan ICAP/1.0\r\nEncapsulated: null-body=0\r\n\r\n"])->await();
            expect($response)->toStartWith('ICAP/1.0 204 No Content');
        }
    } finally {
        $pool->close();
        $server->close();
    }
    $answering->await();
});

/**
 * M06 — request() returns the raw response string, so nothing has checked
 * status or verdict when the transport would give the socket back. It
 * closes the socket, with a pool as well: the pool must connect anew.
 */
it('closes the socket after request() instead of returning it to the pool', function () {
    $replies = [
        'plain 204'         => "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
        'interim 100'       => "ICAP/1.0 100 Continue\r\n\r\n",
        'keep-alive, close' => "ICAP/1.0 204 No Content\r\nConnection: keep-alive, close\r\nEncapsulated: null-body=0\r\n\r\n",
    ];

    foreach ($replies as $case => $reply) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $connections = 0;
        $pool = new AmpConnectionPool(
            connector: function () use ($clientEnd, &$connections) {
                $connections++;
                return $clientEnd;
            },
        );
        $transport = new AsyncAmpTransport($pool);
        $config = new Config('icap.example', streamTimeout: 2.0);

        $answering = async(function () use ($serverEnd, $reply): void {
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && ($chunk = $serverEnd->read()) !== null) {
                $head .= $chunk;
            }
            $serverEnd->write($reply);
        });

        try {
            $response = $transport->request($config, ["OPTIONS icap://icap.example/scan ICAP/1.0\r\nEncapsulated: null-body=0\r\n\r\n"])->await();
            $answering->await();
            expect($response)->toBe($reply, $case);

            // Closed, not just missing from the pool: a socket left open
            // and unreferenced would pass the connection count as well.
            expect($clientEnd->isClosed())->toBeTrue($case);
            $pool->acquire($config);
            expect($connections)->toBe(2, $case);
        } finally {
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    }
});

/**
 * B05 / M10 (first stage) — a write ends with the caller's cancellation and with the stream
 * timeout, and what ended it says so.
 *
 * Socket::write() takes no cancellation: against a peer that does not read it suspends its
 * fiber for good. These tests use real socket pairs and a peer that reads only when a test
 * says so. A watchdog closes the test's own sockets if a write hangs, so a regression fails
 * an assertion instead of stalling the run.
 */

/**
 * A session over one end of a real socket pair; the test keeps the other end. The pool hands
 * out that one socket, so the number of connections says whether a socket came back from it.
 *
 * @return array{session: AmpTransportSession, client: Socket\Socket, peer: Socket\Socket, pool: AmpConnectionPool, opened: \Closure(): int}
 */
function pairedSession(float $streamTimeout, ?Cancellation $cancellation = null, ?float $responseStartTimeout = null): array
{
    [$peer, $client] = Socket\createSocketPair();
    $opened = 0;
    $pool = new AmpConnectionPool(connector: function () use ($client, &$opened): Socket\Socket {
        $opened++;

        return $client;
    });
    $config = $responseStartTimeout === null
        ? new Config('icap.example', streamTimeout: $streamTimeout)
        : new Config('icap.example', streamTimeout: $streamTimeout, responseStartTimeout: $responseStartTimeout);

    return [
        'session' => (new AsyncAmpTransport($pool))->openSession($config, $cancellation),
        'client' => $client,
        'peer' => $peer,
        'pool' => $pool,
        'opened' => static function () use (&$opened): int {
            return $opened;
        },
    ];
}

/**
 * A request body for a peer that never reads: a head the socket takes at once, then a chunk
 * far larger than any socket buffer. The counters tell how far the writer got.
 *
 * @param array{pulled: int, returned: int} $seen
 *
 * @return \Generator<int, string, mixed, void>
 */
function stalledBody(array &$seen): \Generator
{
    $seen['pulled']++;
    yield "RESPMOD icap://icap.example/scan ICAP/1.0\r\nEncapsulated: res-body=0\r\n\r\n";
    $seen['pulled']++;
    yield str_repeat('X', 8 * 1024 * 1024);
    $seen['returned']++;
}

function pollUntil(\Closure $condition, float $seconds): bool
{
    $until = hrtime(true) + (int) ($seconds * 1_000_000_000);
    while (!$condition()) {
        if (hrtime(true) > $until) {
            return false;
        }
        delay(0.005);
    }

    return true;
}

/**
 * Test watchdog: if the test hangs, it closes the sockets the test owns, so the stuck fibers end
 * and the assertion on $hung fails the test instead of the run stalling. Cancel the returned
 * callback when the test is over.
 */
function armWatchdog(Socket\Socket $client, Socket\Socket $peer, bool &$hung): string
{
    return EventLoop::delay(3.0, function () use ($client, $peer, &$hung): void {
        $hung = true;
        $client->close();
        $peer->close();
    });
}

function thrownBy(\Closure $operation): ?\Throwable
{
    try {
        $operation();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * Reads the peer until the end of the stream, or for a second.
 *
 * @return array{string, bool} the bytes that arrived, and whether the end of the stream came
 */
function drain(Socket\Socket $peer): array
{
    $bytes = '';
    try {
        while (($chunk = $peer->read(new TimeoutCancellation(1.0))) !== null) {
            $bytes .= $chunk;
        }
    } catch (CancelledException) {
        return [$bytes, false];
    }

    return [$bytes, true];
}

/**
 * Writes a request whose second chunk is far larger than the socket buffers to a peer that
 * never reads, lets $afterStall act once the write is stuck on that chunk, and reports how the
 * write ended and in what state session and socket were, before anything is cleaned up.
 *
 * @param (\Closure(): void)|null $afterStall
 *
 * @return array{hung: bool, stalled: bool, outcome: \Throwable|null, elapsed: float, returned: int, received: int, clientClosed: bool, peerEof: bool, reconnected: bool}
 */
function stalledWrite(float $streamTimeout, ?Cancellation $caller = null, ?\Closure $afterStall = null): array
{
    $fx = pairedSession($streamTimeout, $caller);
    $seen = ['pulled' => 0, 'returned' => 0];
    $outcome = null;
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        $started = hrtime(true);
        $writer = async(function () use ($fx, &$seen, &$outcome): void {
            try {
                $fx['session']->write(stalledBody($seen));
            } catch (\Throwable $e) {
                $outcome = $e;
            }
        });

        // The second chunk was handed over: the write is stuck on it, the peer reads nothing.
        pollUntil(function () use (&$seen): bool {
            return $seen['pulled'] === 2;
        }, 1.0);
        delay(0.05);
        $stalled = !$writer->isComplete();
        if ($afterStall !== null) {
            $afterStall();
        }
        $writer->await();
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $clientClosed = $fx['client']->isClosed();
        [$bytes, $peerEof] = drain($fx['peer']);
        $fx['session']->release();
        $fx['pool']->acquire(new Config('icap.example'))->close();

        return [
            'hung' => $hung,
            'stalled' => $stalled,
            'outcome' => $outcome,
            'elapsed' => $elapsed,
            'returned' => $seen['returned'],
            'received' => strlen($bytes),
            'clientClosed' => $clientClosed,
            'peerEof' => $peerEof,
            'reconnected' => $fx['opened']() === 2,
        ];
    } finally {
        EventLoop::cancel($watchdog);
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
}

it('pulls nothing from the producer, sends nothing and closes the session for a write cancelled before it began', function () {
    $deferred = new DeferredCancellation();
    $fx = pairedSession(30.0, $deferred->getCancellation());
    $deferred->cancel();
    $pulled = 0;
    $body = (function () use (&$pulled): \Generator {
        $pulled++;
        yield "OPTIONS icap://icap.example/scan ICAP/1.0\r\n\r\n";
    })();

    try {
        $thrown = thrownBy(fn () => $fx['session']->write($body));
        $clientClosed = $fx['client']->isClosed();
        [$received, $peerEof] = drain($fx['peer']);
        $fx['session']->release();
        $fx['pool']->acquire(new Config('icap.example'))->close();

        expect($thrown)->toBeInstanceOf(CancelledException::class);
        expect($pulled)->toBe(0);
        expect($clientClosed)->toBeTrue();
        expect($received)->toBe('');
        expect($peerEof)->toBeTrue();
        expect($fx['opened']())->toBe(2);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('ends a write that is stalled under backpressure when the caller cancels, and closes the session', function () {
    $deferred = new DeferredCancellation();

    $run = stalledWrite(30.0, $deferred->getCancellation(), fn () => $deferred->cancel());

    expect($run['stalled'])->toBeTrue();
    expect($run['hung'])->toBeFalse();
    expect($run['outcome'])->toBeInstanceOf(CancelledException::class);
    expect($run['returned'])->toBe(0);
    expect($run['clientClosed'])->toBeTrue();
    expect($run['peerEof'])->toBeTrue();
    expect($run['received'])->toBeLessThan(8 * 1024 * 1024);
    expect($run['reconnected'])->toBeTrue();
});

it('keeps a caller-supplied TimeoutCancellation a CancelledException, not an IcapTimeoutException', function () {
    $run = stalledWrite(30.0, new TimeoutCancellation(0.15));

    expect($run['hung'])->toBeFalse();
    expect($run['outcome'])->toBeInstanceOf(CancelledException::class);
    expect($run['clientClosed'])->toBeTrue();
    expect($run['reconnected'])->toBeTrue();
});

it('ends a stalled write at the stream timeout as IcapTimeoutException', function () {
    $run = stalledWrite(0.3);

    expect($run['stalled'])->toBeTrue();
    expect($run['hung'])->toBeFalse();
    expect($run['outcome'])->toBeInstanceOf(IcapTimeoutException::class);
    expect($run['elapsed'])->toBeGreaterThanOrEqual(0.25);
    expect($run['returned'])->toBe(0);
    expect($run['clientClosed'])->toBeTrue();
    expect($run['peerEof'])->toBeTrue();
    expect($run['received'])->toBeLessThan(8 * 1024 * 1024);
    expect($run['reconnected'])->toBeTrue();
});

it('ends a stalled write at the stream timeout as IcapTimeoutException when a caller cancellation is live', function () {
    $deferred = new DeferredCancellation();

    $run = stalledWrite(0.15, $deferred->getCancellation());

    expect($run['hung'])->toBeFalse();
    expect($run['outcome'])->toBeInstanceOf(IcapTimeoutException::class);
    expect($run['clientClosed'])->toBeTrue();
    expect($deferred->isCancelled())->toBeFalse();
});

it('completes a write that waits for a slow reader and leaves the session usable', function () {
    $fx = pairedSession(5.0);
    $seen = ['pulled' => 0, 'returned' => 0];
    $received = 0;
    // The peer starts to read only after the write has met backpressure.
    $reader = async(function () use ($fx, &$received): void {
        delay(0.15);
        while (($chunk = $fx['peer']->read()) !== null) {
            $received += strlen($chunk);
        }
    });

    try {
        $started = hrtime(true);
        $fx['session']->write(stalledBody($seen));
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $open = !$fx['client']->isClosed();

        // The session was not harmed: it goes back to the pool and comes out of it again.
        $fx['session']->release();
        $again = $fx['pool']->acquire(new Config('icap.example'));
        $fx['client']->close();
        $reader->await();

        expect($seen['returned'])->toBe(1);
        expect($elapsed)->toBeGreaterThanOrEqual(0.1);
        expect($open)->toBeTrue();
        expect($again)->toBe($fx['client']);
        expect($fx['opened']())->toBe(1);
        expect($received)->toBeGreaterThanOrEqual(8 * 1024 * 1024);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('closes the session when the producer fails mid-request, so release() cannot pool the half-sent request', function () {
    $fx = pairedSession(30.0);
    $body = (function (): \Generator {
        yield "OPTIONS icap://icap.example/scan ICAP/1.0\r\n";
        throw new \RuntimeException('source failed');
    })();

    try {
        $thrown = thrownBy(fn () => $fx['session']->write($body));
        $clientClosed = $fx['client']->isClosed();
        $fx['session']->release();
        $fx['pool']->acquire(new Config('icap.example'))->close();

        expect($thrown)->toBeInstanceOf(\RuntimeException::class);
        expect($thrown?->getMessage())->toBe('source failed');
        expect($clientClosed)->toBeTrue();
        expect($fx['opened']())->toBe(2);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('leaves a healthy session alone after a successful write: no late deadline, no late cancellation', function () {
    // The deadline of a write and the caller's cancellation come after it ended.
    $deferred = new DeferredCancellation();
    $late = pairedSession(0.1, $deferred->getCancellation());
    $replied = pairedSession(0.1);

    try {
        $late['session']->write(["OPTIONS icap://icap.example/scan ICAP/1.0\r\n", "\r\n"]);
        delay(0.3);
        $deferred->cancel();
        delay(0.1);
        expect($late['client']->isClosed())->toBeFalse();

        $replied['session']->write(["OPTIONS icap://icap.example/scan ICAP/1.0\r\n", "\r\n"]);
        delay(0.3);
        $reply = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
        $replied['peer']->write($reply);
        expect($replied['session']->readResponse())->toBe($reply);
        $replied['session']->release();
        $replied['pool']->acquire(new Config('icap.example'));
        expect($replied['opened']())->toBe(1);
    } finally {
        foreach ([$late, $replied] as $fx) {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('removes its timer and its cancellation callback on every outcome, with the exceptions still held', function () {
    // The stack trace of a held exception keeps the arguments of its frames alive.
    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];
    $timers = static function (): int {
        return count(array_filter(
            EventLoop::getIdentifiers(),
            static fn (string $id): bool => EventLoop::getType($id) === CallbackType::Delay,
        ));
    };
    $reply = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $cases = [
        'write completes' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $fx = pairedSession(30.0, $caller);
            $draining = async(function () use ($fx): void {
                while ($fx['peer']->read() !== null) {
                }
            });
            try {
                $fx['session']->write(["OPTIONS icap://icap.example/scan ICAP/1.0\r\n", "\r\n"]);
            } finally {
                $fx['session']->close();
                $draining->await();
                $fx['peer']->close();
                $fx['pool']->close();
            }

            return null;
        },
        'write is cancelled' => fn (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable => stalledWrite(30.0, $caller, fn () => $deferred->cancel())['outcome'],
        'write times out' => fn (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable => stalledWrite(0.15, $caller)['outcome'],
        'write runs over its window in the producer' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $fx = pairedSession(0.03, $caller);
            try {
                return thrownBy(fn () => $fx['session']->write((function (): \Generator {
                    yield "first\r\n";
                    usleep(60_000);
                    yield "second\r\n";
                })()));
            } finally {
                $fx['client']->close();
                $fx['peer']->close();
                $fx['pool']->close();
            }
        },
        'read completes' => function (DeferredCancellation $deferred, Cancellation $caller) use ($reply): ?\Throwable {
            $fx = pairedSession(30.0, $caller);
            try {
                $fx['peer']->write($reply);

                return thrownBy(fn () => $fx['session']->readResponse());
            } finally {
                $fx['client']->close();
                $fx['peer']->close();
                $fx['pool']->close();
            }
        },
        'read fails inside the reader' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $fx = pairedSession(30.0, $caller);
            try {
                $fx['peer']->write("ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n5\r\nab");
                $fx['peer']->close();

                return thrownBy(fn () => $fx['session']->readResponse());
            } finally {
                $fx['client']->close();
                $fx['pool']->close();
            }
        },
        'read times out' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $fx = pairedSession(0.15, $caller);
            $hung = false;
            $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);
            try {
                $thrown = thrownBy(fn () => $fx['session']->readResponse());
                expect($hung)->toBeFalse();

                return $thrown;
            } finally {
                EventLoop::cancel($watchdog);
                $fx['client']->close();
                $fx['peer']->close();
                $fx['pool']->close();
            }
        },
        'read is cancelled' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $fx = pairedSession(5.0, $caller);
            $cancelling = EventLoop::delay(0.05, fn () => $deferred->cancel());
            try {
                return thrownBy(fn () => $fx['session']->readResponse());
            } finally {
                EventLoop::cancel($cancelling);
                $fx['client']->close();
                $fx['peer']->close();
                $fx['pool']->close();
            }
        },
        'connection setup fails' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $pool = new AmpConnectionPool(connector: function (): never {
                throw new IcapConnectionException('refused');
            });

            return thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(new Config('icap.example'), $caller));
        },
        'connection setup times out' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            $pool = new AmpConnectionPool(connector: function (Config $config, ?Cancellation $cancellation): never {
                delay(10.0, true, $cancellation);

                throw new \LogicException('the connection attempt ran to the end');
            });

            return thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(new Config('icap.example', streamTimeout: 0.15), $caller));
        },
        'connection setup is discarded when the connector returns late' => function (DeferredCancellation $deferred, Cancellation $caller): ?\Throwable {
            [$peer, $client] = Socket\createSocketPair();
            $pool = new AmpConnectionPool(connector: function () use ($client): Socket\Socket {
                usleep(60_000);

                return $client;
            });
            try {
                return thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(new Config('icap.example', streamTimeout: 0.03), $caller));
            } finally {
                $client->close();
                $peer->close();
                $pool->close();
            }
        },
    ];

    try {
        foreach ($cases as $case => $run) {
            $deferred = new DeferredCancellation();
            $spy = new class ($deferred->getCancellation()) implements Cancellation {
                /** @var array<string, true> */
                public array $active = [];

                public function __construct(private readonly Cancellation $inner)
                {
                }

                #[\Override]
                public function subscribe(\Closure $callback): string
                {
                    $id = $this->inner->subscribe($callback);
                    $this->active[$id] = true;

                    return $id;
                }

                #[\Override]
                public function unsubscribe(string $id): void
                {
                    unset($this->active[$id]);
                    $this->inner->unsubscribe($id);
                }

                #[\Override]
                public function isRequested(): bool
                {
                    return $this->inner->isRequested();
                }

                #[\Override]
                public function throwIfRequested(): void
                {
                    $this->inner->throwIfRequested();
                }
            };
            $before = $timers();

            $held[] = $run($deferred, $spy);

            expect($spy->active)->toBe([], $case);
            expect($timers())->toBeLessThanOrEqual($before, $case);
        }
    } finally {
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
    expect(array_filter($held))->toHaveCount(9);
});

it('stops at the next chunk once the caller cancels, without waiting for the event loop', function () {
    // The producer itself gives up, so no callback of the loop can run in between.
    $cases = [
        'before the next chunk is written' => static function (DeferredCancellation $deferred): \Generator {
            yield "first\r\n";
            $deferred->cancel();
            yield "second\r\n";
            yield "third\r\n";
        },
        'while the producer ends' => static function (DeferredCancellation $deferred): \Generator {
            yield "first\r\n";
            $deferred->cancel();
        },
    ];

    foreach ($cases as $case => $producer) {
        $deferred = new DeferredCancellation();
        $fx = pairedSession(30.0, $deferred->getCancellation());

        try {
            $thrown = thrownBy(fn () => $fx['session']->write($producer($deferred)));
            [$received, $peerEof] = drain($fx['peer']);

            expect($thrown)->toBeInstanceOf(CancelledException::class, $case);
            expect($received)->toBe("first\r\n", $case);
            expect($peerEof)->toBeTrue($case);
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('checks the caller\'s cancellation after each write, before asking the producer for the next chunk', function () {
    $deferred = new DeferredCancellation();
    /** @var Socket\Socket&\Mockery\MockInterface $socket */
    $socket = m::mock(Socket\Socket::class);
    // The caller gives up while the first chunk is being written.
    /** @var \Mockery\Expectation $write */
    $write = $socket->shouldReceive('write');
    $write->once()->andReturnUsing(function () use ($deferred): void {
        $deferred->cancel();
    });
    /** @var \Mockery\Expectation $close */
    $close = $socket->shouldReceive('close');
    $close->atLeast()->once();
    $pulled = 0;
    $producer = (function () use (&$pulled): \Generator {
        $pulled++;
        yield 'first';
        $pulled++;
        yield 'second';
    })();
    $session = new AmpTransportSession(
        config: new Config('icap.example'),
        socket: $socket,
        streamTimeout: 30.0,
        userCancellation: $deferred->getCancellation(),
        maxResponseSize: 1_000_000,
        maxHeaderLineLength: 8192,
        pool: null,
    );

    $thrown = thrownBy(fn () => $session->write($producer));

    expect($thrown)->toBeInstanceOf(CancelledException::class);
    expect($pulled)->toBe(1);
    m::close();
});

/**
 * AP06-R01 — the producer is part of the call. Whenever it hands control back, the window is
 * looked at, and an empty chunk is no exception: it never reaches the socket, so no failing
 * write would end the call for it.
 */
it('stops asking the producer once the window has passed, also when every chunk is empty', function () {
    $fx = pairedSession(0.05);
    $produced = 0;
    // Eight empty chunks, 30 ms apart: the call is over its window with the second.
    $body = (function () use (&$produced): \Generator {
        for ($i = 0; $i < 8; $i++) {
            delay(0.03);
            $produced++;
            yield '';
        }
    })();

    try {
        $thrown = thrownBy(fn () => $fx['session']->write($body));
        $asked = $produced;
        $clientClosed = $fx['client']->isClosed();
        [$received, $peerEof] = drain($fx['peer']);
        $fx['session']->release();
        $fx['pool']->acquire(new Config('icap.example'))->close();

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        expect($asked)->toBeLessThanOrEqual(2);
        expect($clientClosed)->toBeTrue();
        expect($received)->toBe('');
        expect($peerEof)->toBeTrue();
        expect($fx['opened']())->toBe(2);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('does not let a producer that blocks the event loop send on or succeed after the window has passed', function () {
    // The timer of the window cannot fire while the producer holds the event loop. The window
    // has passed all the same: nothing is sent for it, and the call does not return as if not.
    $cases = [
        'before the first chunk' => [
            static function (): \Generator {
                usleep(120_000);
                yield "first\r\n";
            },
            '',
        ],
        'between two chunks' => [
            static function (): \Generator {
                yield "first\r\n";
                usleep(120_000);
                yield "second\r\n";
            },
            "first\r\n",
        ],
    ];

    foreach ($cases as $case => [$producer, $sent]) {
        $fx = pairedSession(0.04);

        try {
            $thrown = thrownBy(fn () => $fx['session']->write($producer()));
            $clientClosed = $fx['client']->isClosed();
            [$received, $peerEof] = drain($fx['peer']);
            $fx['session']->release();
            $fx['pool']->acquire(new Config('icap.example'))->close();

            expect($thrown)->toBeInstanceOf(IcapTimeoutException::class, $case);
            expect($clientClosed)->toBeTrue($case);
            expect($received)->toBe($sent, $case);
            expect($peerEof)->toBeTrue($case);
            expect($fx['opened']())->toBe(2, $case);
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('reports a cancellation of the caller, not a timeout, when the window has passed as well', function () {
    // Whatever is waiting takes 0.4 s, longer than the window (0.1 s) and the caller (0.15 s),
    // and only then fails the way a call that was ended from outside fails.
    $window = 0.1;
    $config = new Config('icap.example', streamTimeout: $window);
    $session = function (Socket\Socket $socket, ?Cancellation $caller) use ($config, $window): AmpTransportSession {
        return new AmpTransportSession($config, $socket, $window, $caller, 1_000_000, 8192, null);
    };
    $cases = [
        'write' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($session): void {
            /** @var \Mockery\Expectation $write */
            $write = $socket->shouldReceive('write');
            $write->andReturnUsing(function (): void {
                delay(0.4);

                throw new ClosedException('closed');
            });
            $session($socket, $caller)->write(['chunk']);
        },
        'read' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($session): void {
            /** @var \Mockery\Expectation $read */
            $read = $socket->shouldReceive('read');
            $read->andReturnUsing(function (): never {
                delay(0.4);

                throw new CancelledException();
            });
            $session($socket, $caller)->readResponse();
        },
        'connection setup' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($config): void {
            $pool = new AmpConnectionPool(connector: function (): never {
                delay(0.4);

                throw new CancelledException();
            });
            (new AsyncAmpTransport($pool))->openSession($config, $caller);
        },
    ];

    foreach ($cases as $case => $run) {
        $deferred = new DeferredCancellation();
        /** @var Socket\Socket&\Mockery\MockInterface $socket */
        $socket = m::mock(Socket\Socket::class);
        /** @var \Mockery\Expectation $close */
        $close = $socket->shouldReceive('close');
        $close->zeroOrMoreTimes();
        $cancelling = EventLoop::delay(0.15, fn () => $deferred->cancel());

        try {
            $thrown = thrownBy(fn () => $run($socket, $deferred->getCancellation()));

            expect($thrown)->toBeInstanceOf(CancelledException::class, $case);
        } finally {
            EventLoop::cancel($cancelling);
        }
    }
    m::close();
});

it('leaves a cancellation alone that neither the caller nor the window caused', function () {
    // Something else cancelled: that is neither the stream timeout nor the caller, so it is
    // passed on as it is, not renamed and not swallowed.
    $config = new Config('icap.example', streamTimeout: 5.0);
    $cases = [
        'write' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($config): void {
            $session = new AmpTransportSession($config, $socket, 5.0, $caller, 1_000_000, 8192, null);
            $session->write((function (): \Generator {
                yield 'chunk';

                throw new CancelledException();
            })());
        },
        'read' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($config): void {
            /** @var \Mockery\Expectation $read */
            $read = $socket->shouldReceive('read');
            $read->andReturnUsing(function (): never {
                throw new CancelledException();
            });
            (new AmpTransportSession($config, $socket, 5.0, $caller, 1_000_000, 8192, null))->readResponse();
        },
        'connection setup' => function (Socket\Socket&\Mockery\MockInterface $socket, Cancellation $caller) use ($config): void {
            $pool = new AmpConnectionPool(connector: function (): never {
                throw new CancelledException();
            });
            (new AsyncAmpTransport($pool))->openSession($config, $caller);
        },
    ];

    foreach ($cases as $case => $run) {
        $deferred = new DeferredCancellation();
        /** @var Socket\Socket&\Mockery\MockInterface $socket */
        $socket = m::mock(Socket\Socket::class);
        /** @var \Mockery\Expectation $write */
        $write = $socket->shouldReceive('write');
        $write->zeroOrMoreTimes();
        /** @var \Mockery\Expectation $close */
        $close = $socket->shouldReceive('close');
        $close->zeroOrMoreTimes();

        $thrown = thrownBy(fn () => $run($socket, $deferred->getCancellation()));

        expect($thrown)->toBeInstanceOf(CancelledException::class, $case);
    }
    m::close();
});

/**
 * M10 (first stage) — the boundaries that already had a timer say what ended them.
 */
it('turns a response that does not arrive within the stream timeout into IcapTimeoutException and closes the session', function () {
    $fx = pairedSession(0.15);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        $thrown = thrownBy(fn () => $fx['session']->readResponse());
        $clientClosed = $fx['client']->isClosed();

        expect($hung)->toBeFalse();
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        expect($clientClosed)->toBeTrue();
    } finally {
        EventLoop::cancel($watchdog);
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('keeps a cancellation of the caller, also a TimeoutCancellation, a CancelledException while the response is read', function () {
    foreach (['cancels while waiting', 'TimeoutCancellation'] as $case) {
        $deferred = new DeferredCancellation();
        $caller = $case === 'TimeoutCancellation' ? new TimeoutCancellation(0.1) : $deferred->getCancellation();
        $fx = pairedSession(5.0, $caller);
        $cancelling = EventLoop::delay(0.1, fn () => $deferred->cancel());

        try {
            $started = hrtime(true);
            $thrown = thrownBy(fn () => $fx['session']->readResponse());
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            $clientClosed = $fx['client']->isClosed();

            expect($thrown)->toBeInstanceOf(CancelledException::class, $case);
            expect($elapsed)->toBeLessThan(2.0, $case);
            expect($clientClosed)->toBeTrue($case);
        } finally {
            EventLoop::cancel($cancelling);
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('tells the stream timeout of the connection setup from a cancellation of the caller', function () {
    // A connection that never comes up; only cancellation ends the wait.
    $connector = static function (Config $config, ?Cancellation $cancellation): Socket\Socket {
        delay(10.0, true, $cancellation);

        throw new \LogicException('the connection attempt ran to the end');
    };
    $cases = [
        'stream timeout' => IcapTimeoutException::class,
        'stream timeout, live cancellation of the caller' => IcapTimeoutException::class,
        'caller cancels' => CancelledException::class,
        'caller TimeoutCancellation' => CancelledException::class,
    ];

    foreach ($cases as $case => $expected) {
        $deferred = new DeferredCancellation();
        $caller = match ($case) {
            'stream timeout' => null,
            'caller TimeoutCancellation' => new TimeoutCancellation(0.1),
            default => $deferred->getCancellation(),
        };
        $streamTimeout = str_starts_with($case, 'stream timeout') ? 0.15 : 5.0;
        $cancelling = $case === 'caller cancels' ? EventLoop::delay(0.1, fn () => $deferred->cancel()) : null;
        $pool = new AmpConnectionPool(connector: $connector);

        try {
            $started = hrtime(true);
            $thrown = thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(
                new Config('icap.example', streamTimeout: $streamTimeout),
                $caller,
            ));
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;

            expect($thrown)->toBeInstanceOf($expected, $case);
            // The caller ends the wait at once, not at the end of the window.
            expect($elapsed)->toBeLessThan(2.0, $case);
        } finally {
            if ($cancelling !== null) {
                EventLoop::cancel($cancelling);
            }
            $pool->close();
        }
    }
});

/**
 * AP06-R02 — a connector need not honour the token it is given. A connection that was cancelled
 * before it began does not reach it; what it hands back after the caller gave up or the window
 * passed is closed, not used.
 */
it('does not ask the connector for a connection that was cancelled before it began', function () {
    [$peer, $client] = Socket\createSocketPair();
    $deferred = new DeferredCancellation();
    $deferred->cancel();
    $asked = 0;
    $pool = new AmpConnectionPool(connector: function () use ($client, &$asked): Socket\Socket {
        $asked++;

        return $client;
    });

    try {
        $thrown = thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(
            new Config('icap.example'),
            $deferred->getCancellation(),
        ));

        expect($thrown)->toBeInstanceOf(CancelledException::class);
        expect($asked)->toBe(0);
    } finally {
        $client->close();
        $peer->close();
        $pool->close();
    }
});

it('hands out the session of a connector that answers late but within the window, and leaves its socket alone', function () {
    // The control for the two aborts below: only a socket that is too late, or whose caller gave
    // up, is closed.
    [$peer, $client] = Socket\createSocketPair();
    $deferred = new DeferredCancellation();
    $pool = new AmpConnectionPool(connector: function () use ($client): Socket\Socket {
        delay(0.1);

        return $client;
    });

    try {
        $session = (new AsyncAmpTransport($pool))->openSession(
            new Config('icap.example', streamTimeout: 5.0),
            $deferred->getCancellation(),
        );
        $open = !$client->isClosed();
        $session->write(["OPTIONS icap://icap.example/scan ICAP/1.0\r\n\r\n"]);
        $received = $peer->read(new TimeoutCancellation(1.0));

        // The caller gives up after the session is open: not a reason to touch a healthy socket.
        $deferred->cancel();
        delay(0.05);
        $openAfterCancel = !$client->isClosed();
        $session->release();
        $again = $pool->acquire(new Config('icap.example'));

        expect($open)->toBeTrue();
        expect($received)->toBe("OPTIONS icap://icap.example/scan ICAP/1.0\r\n\r\n");
        expect($openAfterCancel)->toBeTrue();
        expect($again)->toBe($client);
    } finally {
        $client->close();
        $peer->close();
        $pool->close();
    }
});

it('closes a socket that the connector hands back after the caller gave up or the window passed, and opens no session', function () {
    // [window, expected exception, when the caller cancels (seconds, null: not by the clock), connector]
    $cases = [
        'the caller cancels inside a connector that returns at once' => [
            5.0,
            CancelledException::class,
            null,
            static function (Socket\Socket $client, DeferredCancellation $deferred): Socket\Socket {
                $deferred->cancel();

                return $client;
            },
        ],
        'the caller cancels while a connector that ignores its token waits' => [
            5.0,
            CancelledException::class,
            0.05,
            static function (Socket\Socket $client): Socket\Socket {
                delay(0.15);

                return $client;
            },
        ],
        'the window passes while a connector that ignores its token waits' => [
            0.04,
            IcapTimeoutException::class,
            null,
            static function (Socket\Socket $client): Socket\Socket {
                delay(0.12);

                return $client;
            },
        ],
        'the window passes in a connector that blocks the event loop' => [
            0.04,
            IcapTimeoutException::class,
            null,
            static function (Socket\Socket $client): Socket\Socket {
                usleep(120_000);

                return $client;
            },
        ],
    ];

    foreach ($cases as $case => [$window, $expected, $cancelAfter, $connect]) {
        [$peer, $client] = Socket\createSocketPair();
        $deferred = new DeferredCancellation();
        $asked = 0;
        $pool = new AmpConnectionPool(connector: function () use ($connect, $client, $deferred, &$asked): Socket\Socket {
            $asked++;

            return $connect($client, $deferred);
        });
        $cancelling = $cancelAfter === null ? null : EventLoop::delay($cancelAfter, fn () => $deferred->cancel());

        try {
            $thrown = thrownBy(fn () => (new AsyncAmpTransport($pool))->openSession(
                new Config('icap.example', streamTimeout: $window),
                $deferred->getCancellation(),
            ));
            $clientClosed = $client->isClosed();
            [, $peerEof] = drain($peer);

            expect($thrown)->toBeInstanceOf($expected, $case);
            expect($asked)->toBe(1, $case);
            expect($clientClosed)->toBeTrue($case);
            expect($peerEof)->toBeTrue($case);
        } finally {
            if ($cancelling !== null) {
                EventLoop::cancel($cancelling);
            }
            $client->close();
            $peer->close();
            $pool->close();
        }
    }
});

/*
 * B02 / B03 — the session owns the bytes it has read. A response ends where its layout says,
 * not where a socket read happens to end, and what lies behind it is the session's to keep or
 * to refuse: it is never dropped, and never the answer of the next request.
 */

it('frames a response whose payload holds a terminator and a forged response, whatever the reads', function () {
    $forged = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $payload = "prefix\r\n0\r\n\r\n" . $forged . 'suffix';
    $head = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($payload) . "\r\n\r\n";
    $wire = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head
        . dechex(strlen($payload)) . "\r\n" . $payload . "\r\n0\r\n\r\n";
    // The first part ends right behind the terminator in the payload.
    $cut = strpos($wire, 'prefix') + strlen("prefix\r\n0\r\n\r\n");
    $fx = pairedSession(5.0);

    try {
        $writer = async(function () use ($fx, $wire, $cut): void {
            $fx['peer']->write(substr($wire, 0, $cut));
            delay(0.05);
            $fx['peer']->write(substr($wire, $cut));
        });

        expect($fx['session']->readResponse())->toBe($wire);
        $writer->await();

        // Nothing is left over, so the socket goes back to the pool and comes out of it again.
        $fx['session']->release();
        $fx['pool']->acquire(new Config('icap.example'));
        expect($fx['opened']())->toBe(1);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('keeps the bytes that came in with a response and hands them to the next read', function () {
    $first = "ICAP/1.0 100 Continue\r\n\r\n";
    $second = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $fx = pairedSession(0.5);

    try {
        // One write holds a response, a whole second one and the start of a third.
        $fx['peer']->write($first . $second . 'ICAP/1.');

        expect($fx['session']->readResponse())->toBe($first);
        expect($fx['session']->readResponse())->toBe($second);

        // The third is not complete and the peer says nothing more: that is a timeout, not a response.
        expect(thrownBy(fn () => $fx['session']->readResponse()))->toBeInstanceOf(IcapTimeoutException::class);
        expect($fx['client']->isClosed())->toBeTrue();
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('refuses to write on and closes the session when bytes no request waits for are buffered', function () {
    $reply = "ICAP/1.0 100 Continue\r\n\r\n";
    $fx = pairedSession(2.0);

    try {
        $fx['peer']->write($reply . "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
        expect($fx['session']->readResponse())->toBe($reply);

        // The server has answered ahead of the request it would answer; the continuation is not sent.
        $thrown = thrownBy(fn () => $fx['session']->write(["5\r\nhello\r\n0\r\n\r\n"]));
        $clientClosed = $fx['client']->isClosed();
        [$bytes] = drain($fx['peer']);

        expect($thrown)->toBeInstanceOf(IcapMalformedResponseException::class);
        expect($clientClosed)->toBeTrue();
        expect($bytes)->toBe('');
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('does not give a socket back to the pool while it holds bytes that were not read as a response', function () {
    $reply = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $fx = pairedSession(2.0);

    try {
        $fx['peer']->write($reply . "surplus");
        expect($fx['session']->readResponse())->toBe($reply);

        $fx['session']->release();
        $closed = $fx['client']->isClosed();
        $fx['pool']->acquire(new Config('icap.example'))->close();

        // Closed, not just missing from the pool; the pool has to connect anew.
        expect($closed)->toBeTrue();
        expect($fx['opened']())->toBe(2);
    } finally {
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

/*
 * AP-15 (M02) — a response that is received and not kept: read to its last byte as it arrives, the size of its body
 * counted, the bytes behind it left to the session, and the socket given back only after a legal end.
 */

it('receives a response over the size limit without keeping it, and gives the socket back after a legal end', function () {
    $size = 12 * 1024 * 1024;
    $echo = iterator_to_array(\Ndrstmr\Icap\Tests\IcapTestServer::echo($size), false);

    foreach (['kept' => false, 'not kept' => true] as $how => $discard) {
        $fx = pairedSession(30.0);
        try {
            $writer = async(static function () use ($fx, $echo): void {
                foreach ($echo as $part) {
                    $fx['peer']->write($part);
                }
            });

            if ($discard) {
                $response = $fx['session']->readResponseDiscardingBody(2 * $size);
                $writer->await();
                expect($response->statusCode)->toBe(200)
                    ->and($response->body)->toBe('')
                    ->and($response->discardedBodyBytes)->toBe($size);

                // A legal end and nothing behind it: the socket goes back to the pool and comes out of it again.
                $fx['session']->release();
                $fx['pool']->acquire(new Config('icap.example'));
                expect($fx['opened']())->toBe(1, $how);
            } else {
                // The configuration allows 10 MiB.
                expect(thrownBy(fn () => $fx['session']->readResponse()))->toBeInstanceOf(IcapMalformedResponseException::class);
                expect($fx['client']->isClosed())->toBeTrue($how);
                $fx['peer']->close();
                $writer->ignore();
            }
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('refuses the bytes that follow a response it did not keep, and closes the session', function () {
    $first = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n5\r\nhello\r\n0\r\n\r\n";

    // label => what comes in behind the response, in the same read
    foreach (['a second response' => "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n", 'a surplus' => 'surplus'] as $case => $behind) {
        $fx = pairedSession(2.0);
        try {
            $fx['peer']->write($first . $behind);

            expect(thrownBy(fn () => $fx['session']->readResponseDiscardingBody(1 << 20)))->toBeInstanceOf(IcapMalformedResponseException::class, $case);
            expect($fx['client']->isClosed())->toBeTrue($case);
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('closes the session when a response that is not kept is illegal at its end, cut off or over its budget', function () {
    $head = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n";
    // label => [what the peer sends, whether it then hangs up, the budget]
    $cases = [
        'invalid trailer'          => [$head . "5\r\nhello\r\n0\r\nnot a field\r\n\r\n", false, 1 << 20],
        'no CRLF behind the data'  => [$head . "5\r\nhelloXX0\r\n\r\n", false, 1 << 20],
        'cut off in the body'      => [$head . "10\r\nhello", true, 1 << 20],
        'over the budget by a byte' => [$head . "5\r\nhello\r\n0\r\n\r\n", false, strlen($head . "5\r\nhello\r\n0\r\n\r\n") - 1],
    ];

    foreach ($cases as $case => [$wire, $hangUp, $budget]) {
        $fx = pairedSession(2.0);
        try {
            $fx['peer']->write($wire);
            if ($hangUp) {
                $fx['peer']->close();
            }

            expect(thrownBy(fn () => $fx['session']->readResponseDiscardingBody($budget)))->toBeInstanceOf(IcapMalformedResponseException::class, $case);
            expect($fx['client']->isClosed())->toBeTrue($case);
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('fails a response that is illegal or that the stream cuts off, and closes the session', function () {
    $head = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n";
    $wire = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head . "5\r\nhello\r\n0\r\n\r\n";
    // label => [what the peer sends, whether it then hangs up]
    $cases = [
        'chunk size that is no number'  => [str_replace("5\r\nhello", "ZZ\r\nhello", $wire), false],
        'chunk size that overflows'     => [str_replace("5\r\nhello", "ffffffffffffffff\r\nhello", $wire), false],
        'no CRLF behind the chunk data' => [str_replace("hello\r\n0", "helloXX0", $wire), false],
        'final 200 without Encapsulated' => ["ICAP/1.0 200 OK\r\nISTag: \"x\"\r\n\r\n", false],
        'cut off in the head'           => [substr($wire, 0, 20), true],
        'cut off in the header section' => [explode('Content-Length', $wire, 2)[0], true],
        'cut off in the body'           => [explode('hello', $wire, 2)[0] . 'he', true],
        'cut off before the trailer'    => [substr($wire, 0, -2), true],
    ];

    foreach ($cases as $case => [$sent, $hangUp]) {
        $fx = pairedSession(2.0);

        try {
            $fx['peer']->write($sent);
            if ($hangUp) {
                $fx['peer']->close();
            }

            $thrown = thrownBy(fn () => $fx['session']->readResponse());
            $clientClosed = $fx['client']->isClosed();
            $fx['session']->release();
            $fx['pool']->acquire(new Config('icap.example'))->close();

            expect($thrown)->toBeInstanceOf(IcapMalformedResponseException::class, $case);
            expect($clientClosed)->toBeTrue($case);
            expect($fx['opened']())->toBe(2, $case);
        } finally {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('holds a response to the header limits of the configuration', function () {
    [$peer, $client] = Socket\createSocketPair();
    $pool = new AmpConnectionPool(connector: fn () => $client);
    $config = (new Config('icap.example', streamTimeout: 2.0))->withLimits(maxHeaderCount: 2);
    $session = (new AsyncAmpTransport($pool))->openSession($config);

    try {
        // Three header lines where the configuration allows two.
        $peer->write("ICAP/1.0 204 No Content\r\nX-A: 1\r\nX-B: 2\r\nEncapsulated: null-body=0\r\n\r\n");

        expect(thrownBy(fn () => $session->readResponse()))->toBeInstanceOf(IcapMalformedResponseException::class);
        expect($client->isClosed())->toBeTrue();
    } finally {
        $client->close();
        $peer->close();
        $pool->close();
    }
});

/*
 * M04 — a pool with a cap holds the transport to it: a session is a connection, and the room is back when
 * the session ends, however it ends. A call that waits for room waits inside the window of the transport.
 */
it('opens no more sessions than the pool allows, request() included, and has the room back when a session ends', function () {
    $pairs = [];
    $opened = 0;
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: function () use (&$pairs, &$opened): Socket\Socket {
            $opened++;
            $pairs[] = $pair = Socket\createSocketPair();

            return $pair[1];
        },
    );
    $transport = new AsyncAmpTransport($pool);
    $config = new Config('icap.example', streamTimeout: 1.0);

    try {
        $first = $transport->openSession($config);
        expect(thrownBy(fn () => $transport->openSession($config)))->toBeInstanceOf(IcapConnectionException::class);
        expect(thrownBy(fn () => $transport->request($config, [])->await()))->toBeInstanceOf(IcapConnectionException::class);
        expect($opened)->toBe(1);

        // The session ends by closing its socket: the room is back at once.
        $first->close();
        $second = $transport->openSession($config);
        expect($opened)->toBe(2);

        // Released, the socket stays counted and is the one handed out next: no third connection.
        $second->release();
        $third = $transport->openSession($config);
        expect($opened)->toBe(2);
        $third->close();
    } finally {
        foreach ($pairs as [$peer, $client]) {
            $client->close();
            $peer->close();
        }
        $pool->close();
    }
});

it('keeps a wait for room inside the stream timeout of the transport and the cancellation of the caller', function () {
    $pairs = [];
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: function () use (&$pairs): Socket\Socket {
            $pairs[] = $pair = Socket\createSocketPair();

            return $pair[1];
        },
        maxWaiters: 1,
        waitTimeout: 5.0,
    );
    $transport = new AsyncAmpTransport($pool);
    $waiting = static function () use ($pool): int {
        $waiters = (new ReflectionProperty($pool, 'waiters'))->getValue($pool);

        return is_array($waiters) ? count($waiters) : -1;
    };

    try {
        $held = $transport->openSession(new Config('icap.example', streamTimeout: 5.0));

        // The window of the transport is shorter than the wait of the pool: its end is the transport's timeout.
        $started = hrtime(true);
        $thrown = thrownBy(fn () => $transport->openSession(new Config('icap.example', streamTimeout: 0.05)));
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0);
        expect($waiting())->toBe(0);

        // The caller's cancellation ends the wait as what it is.
        $deferred = new DeferredCancellation();
        $cancelling = EventLoop::delay(0.05, fn () => $deferred->cancel());
        try {
            $thrown = thrownBy(fn () => $transport->openSession(new Config('icap.example', streamTimeout: 5.0), $deferred->getCancellation()));
        } finally {
            EventLoop::cancel($cancelling);
        }
        expect($thrown)->toBeInstanceOf(CancelledException::class);
        expect($waiting())->toBe(0);

        // Neither lost the room: the session that held it ends and the next one is served.
        $held->close();
        $next = $transport->openSession(new Config('icap.example', streamTimeout: 1.0));
        $next->close();
    } finally {
        foreach ($pairs as [$peer, $client]) {
            $client->close();
            $peer->close();
        }
        $pool->close();
    }
});

/*
 * AP-12 (M10) — a response has two limits, one after the other: the server's time to begin its answer once the request
 * is sent (a scan can take long), and, from the first byte on, a maximum for the answer to arrive in full. The second is
 * no idle timer: a peer that drips the answer does not keep the call alive.
 */

/** The frame a session reads in these tests, and how long it is. */
const PHASE_FRAME = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

/** @return list<string> the timers (delays and repeats) the event loop holds */
function phaseTimers(): array
{
    return array_values(array_filter(
        EventLoop::getIdentifiers(),
        static fn (string $id): bool => in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true),
    ));
}

it('gives the server the response start timeout to begin its answer, longer than the stream timeout', function () {
    $timers = phaseTimers();
    $fx = pairedSession(0.2, null, 2.0);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        // The scan takes 0.5 seconds: more than the stream timeout, within the time allowed for it to begin.
        $answering = async(static function () use ($fx): void {
            delay(0.5);
            $fx['peer']->write(PHASE_FRAME);
        });
        $started = hrtime(true);
        $frame = $fx['session']->readResponse();
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $answering->await();

        expect($hung)->toBeFalse();
        expect($frame)->toBe(PHASE_FRAME);
        expect($elapsed)->toBeGreaterThanOrEqual(0.45)->toBeLessThan(2.0);
        expect($fx['client']->isClosed())->toBeFalse();
        // Both stretches of the call set a timer, and none is left: the first was replaced, the second removed.
        EventLoop::cancel($watchdog);
        expect(phaseTimers())->toBe($timers);
    } finally {
        EventLoop::cancel($watchdog);
        $fx['session']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('ends a response that does not begin within the response start timeout, shorter than the stream timeout', function () {
    $fx = pairedSession(5.0, null, 0.2);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        $started = hrtime(true);
        $thrown = thrownBy(fn () => $fx['session']->readResponse());
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $clientClosed = $fx['client']->isClosed();

        expect($hung)->toBeFalse();
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        expect($elapsed)->toBeGreaterThanOrEqual(0.15)->toBeLessThan(2.0);
        expect($clientClosed)->toBeTrue();
    } finally {
        EventLoop::cancel($watchdog);
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('ends an answer that is dripped past the stream timeout, however long the time to begin it was', function () {
    $fx = pairedSession(0.4, null, 5.0);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        // The first byte at once, then one byte every 0.1 seconds: each byte is "progress", and the frame would take
        // five seconds. An idle timer that every byte starts again would wait for all of it.
        $dripping = async(static function () use ($fx): void {
            foreach (str_split(PHASE_FRAME) as $byte) {
                if ($fx['peer']->isClosed()) {
                    return;
                }
                try {
                    $fx['peer']->write($byte);
                } catch (\Throwable) {
                    return;
                }
                delay(0.1);
            }
        });
        $started = hrtime(true);
        $thrown = thrownBy(fn () => $fx['session']->readResponse());
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $clientClosed = $fx['client']->isClosed();
        $fx['peer']->close();
        $dripping->await();

        expect($hung)->toBeFalse();
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        // The stream timeout from the first byte, not the 5 seconds of the start, and not the end of the frame.
        expect($elapsed)->toBeGreaterThanOrEqual(0.35)->toBeLessThan(2.0);
        expect($clientClosed)->toBeTrue();
    } finally {
        EventLoop::cancel($watchdog);
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('lets an answer arrive in parts as long as it arrives within the stream timeout', function () {
    $fx = pairedSession(0.6, null, 0.2);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        // The response start timeout (0.2) is spent before the first part; the parts then have 0.6 seconds in all.
        $parts = [substr(PHASE_FRAME, 0, 10), substr(PHASE_FRAME, 10, 20), substr(PHASE_FRAME, 30)];
        $answering = async(static function () use ($fx, $parts): void {
            delay(0.1);
            foreach ($parts as $part) {
                $fx['peer']->write($part);
                delay(0.15);
            }
        });
        $frame = $fx['session']->readResponse();
        $answering->await();

        expect($hung)->toBeFalse();
        expect($frame)->toBe(PHASE_FRAME);
    } finally {
        EventLoop::cancel($watchdog);
        $fx['session']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

it('does not wait for the start of an answer that has begun: bytes behind the last response count as begun', function () {
    $fx = pairedSession(0.4, null, 30.0);
    $hung = false;
    $watchdog = armWatchdog($fx['client'], $fx['peer'], $hung);

    try {
        // Two responses in one read, the second cut off: the first call returns the first frame, and the second
        // finds the start of its answer in the session already, so only the stream timeout is left for the rest.
        $fx['peer']->write(PHASE_FRAME . substr(PHASE_FRAME, 0, 12));
        expect($fx['session']->readResponse())->toBe(PHASE_FRAME);

        $started = hrtime(true);
        $thrown = thrownBy(fn () => $fx['session']->readResponse());
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        expect($hung)->toBeFalse();
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class);
        expect($elapsed)->toBeLessThan(2.0);
    } finally {
        EventLoop::cancel($watchdog);
        $fx['client']->close();
        $fx['peer']->close();
        $fx['pool']->close();
    }
});

/*
 * AP-12 (M10) — the TLS handshake is a phase of its own. A server that accepts the connection and says nothing never
 * answers the handshake; its limit is tlsHandshakeTimeout, not the stream timeout, which is longer here, and the
 * connection is closed, with the room it held.
 */
it('ends a TLS handshake that the server never answers at the limit of its own, and closes the connection', function () {
    $server = Socket\listen('127.0.0.1:0');
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    $accepted = [];
    $accepting = async(function () use ($server, &$accepted): void {
        while (($peer = $server->accept()) !== null) {
            $accepted[] = $peer;
        }
    });
    $pool = new AmpConnectionPool();

    try {
        $config = new Config(
            '127.0.0.1',
            $address->getPort(),
            socketTimeout: 5.0,
            streamTimeout: 30.0,
            tlsContext: new ClientTlsContext('127.0.0.1'),
            tlsHandshakeTimeout: 0.3,
        );

        // Without a pool, with the pool that opens a fresh connection each time, and with the keep-alive pool.
        $paths = [
            'no pool'              => new AsyncAmpTransport(),
            'NullConnectionPool'   => new AsyncAmpTransport(new NullConnectionPool()),
            'AmpConnectionPool'    => new AsyncAmpTransport($pool),
        ];
        foreach ($paths as $path => $transport) {
            $baseline = [];
            foreach (EventLoop::getIdentifiers() as $id) {
                if (in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true)) {
                    $baseline[] = $id;
                }
            }

            $started = hrtime(true);
            $thrown = thrownBy(fn () => $transport->openSession($config));
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;

            expect($thrown)->toBeInstanceOf(IcapTimeoutException::class, $path);
            expect($elapsed)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(3.0);

            $timers = [];
            foreach (EventLoop::getIdentifiers() as $id) {
                if (in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true)) {
                    $timers[] = $id;
                }
            }
            expect($timers)->toBe($baseline, $path);
        }

        // The server sees every one of the connections end (after the hello of the handshake that was never answered).
        delay(0.1);
        expect(count($accepted))->toBe(3);
        foreach ($accepted as $peer) {
            expect(drain($peer)[1])->toBeTrue();
        }
        // The pool has the room back: nothing is left of the connections that failed in the handshake.
        $connecting = (new ReflectionProperty($pool, 'connecting'))->getValue($pool);
        expect($connecting)->toBe([]);
        $again = $pool->acquire(new Config('127.0.0.1', $address->getPort(), socketTimeout: 5.0));
        expect($again->isClosed())->toBeFalse();
        $again->close();
    } finally {
        $pool->close();
        $server->close();
        $accepting->await();
        foreach ($accepted as $peer) {
            $peer->close();
        }
    }
});

it('gives a TLS handshake the limit of its own where it is longer than the stream timeout', function () {
    // The connection setup has one window: the stream timeout, or the handshake limit if that is longer.
    $slowConnector = static function (Config $config, ?Cancellation $cancellation): Socket\Socket {
        delay(0.4, true, $cancellation);
        [$peer, $client] = Socket\createSocketPair();
        $peer->close();

        return $client;
    };
    $shortWindow = new Config('icap.example', streamTimeout: 0.15, tlsHandshakeTimeout: 2.0);
    $cases = [
        'no TLS: the stream timeout is the window'                         => [$shortWindow, IcapTimeoutException::class],
        'TLS: the handshake limit is longer, and so is the window'         => [$shortWindow->withTlsContext(new ClientTlsContext('icap.example')), null],
    ];

    foreach ($cases as $case => [$config, $expected]) {
        $pool = new AmpConnectionPool(connector: $slowConnector);
        try {
            $thrown = thrownBy(function () use ($config, $pool): void {
                (new AsyncAmpTransport($pool))->openSession($config)->close();
            });

            if ($expected === null) {
                expect($thrown)->toBeNull($case);
            } else {
                expect($thrown)->toBeInstanceOf($expected, $case);
            }
        } finally {
            $pool->close();
        }
    }
});

/*
 * What goes wrong in the connection reaches the caller as an IcapConnectionException that keeps the cause, however the
 * connection was opened and wherever in the exchange it failed.
 */
it('reports a TLS handshake that fails as IcapConnectionException with its cause, and closes the connection', function () {
    $server = Socket\listen('127.0.0.1:0');
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    $accepted = [];
    $accepting = async(function () use ($server, &$accepted): void {
        while (($peer = $server->accept()) !== null) {
            $accepted[] = $peer;
            $peer->write("ICAP/1.0 400 Bad Request\r\n\r\n");
        }
    });
    $pool = new AmpConnectionPool(maxConnectionsPerHost: 1);
    // The exceptions are held, and with them the arguments of their frames: only an explicit close() ends a connection.
    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];

    try {
        $config = new Config('127.0.0.1', $address->getPort(), socketTimeout: 5.0, streamTimeout: 5.0, tlsContext: new ClientTlsContext('127.0.0.1'));
        $paths = [
            'no pool'            => new AsyncAmpTransport(),
            'NullConnectionPool' => new AsyncAmpTransport(new NullConnectionPool()),
            'AmpConnectionPool'  => new AsyncAmpTransport($pool),
        ];
        foreach ($paths as $path => $transport) {
            $thrown = thrownBy(fn () => $transport->request($config, ["OPTIONS icap://127.0.0.1/svc ICAP/1.0\r\n\r\n"])->await());
            $held[] = $thrown;

            expect($thrown)->toBeInstanceOf(IcapConnectionException::class, $path);
            expect($thrown?->getPrevious())->toBeInstanceOf(Socket\TlsException::class, $path);
        }

        // Every connection that failed was closed, and the pool has its room back: with a cap of one, the next try
        // would be refused for want of room, not for the handshake.
        delay(0.1);
        expect($accepted)->toHaveCount(3);
        foreach ($accepted as $peer) {
            expect(drain($peer)[1])->toBeTrue();
        }
        expect(thrownBy(fn () => $pool->acquire($config))?->getPrevious())->toBeInstanceOf(Socket\TlsException::class);
    } finally {
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
        $pool->close();
        $server->close();
        $accepting->await();
        foreach ($accepted as $peer) {
            $peer->close();
        }
    }
});

it('reports a write that the socket fails as IcapConnectionException with its cause, and leaves a stream error of the producer alone', function () {
    // The peer is gone, so the 8 MiB chunk cannot go out.
    $gone = pairedSession(5.0);
    $gone['peer']->close();
    $seen = ['pulled' => 0, 'returned' => 0];
    // A producer that fails with an Amp stream error of its own, say its upload source: not the connection's failure.
    $own = pairedSession(5.0);
    $sourceFailure = new StreamException('The upload source failed.');

    try {
        $thrown = thrownBy(fn () => $gone['session']->write(stalledBody($seen)));
        $clientClosed = $gone['client']->isClosed();
        $gone['session']->release();
        $gone['pool']->acquire(new Config('icap.example'))->close();

        expect($thrown)->toBeInstanceOf(IcapConnectionException::class);
        expect($thrown?->getPrevious())->toBeInstanceOf(StreamException::class);
        // The session is closed, and what it held does not go back to the pool.
        expect($clientClosed)->toBeTrue();
        expect($gone['opened']())->toBe(2);

        $thrown = thrownBy(fn () => $own['session']->write((function () use ($sourceFailure): \Generator {
            yield "OPTIONS icap://icap.example/scan ICAP/1.0\r\n";

            throw $sourceFailure;
        })()));
        expect($thrown)->toBe($sourceFailure);
    } finally {
        foreach ([$gone, $own] as $fx) {
            $fx['client']->close();
            $fx['peer']->close();
            $fx['pool']->close();
        }
    }
});

it('reports a read that the socket fails as IcapConnectionException with its cause, and closes the session', function () {
    $failure = new StreamException('Failed to read from the stream.');
    /** @var Socket\Socket&\Mockery\MockInterface $socket */
    $socket = m::mock(Socket\Socket::class);
    /** @var \Mockery\Expectation $read */
    $read = $socket->shouldReceive('read');
    $read->andThrow($failure);
    /** @var \Mockery\Expectation $close */
    $close = $socket->shouldReceive('close');
    $close->atLeast()->once();
    $session = new AmpTransportSession(new Config('icap.example', streamTimeout: 5.0), $socket, 5.0, null, 1_000_000, 8192, null);

    $thrown = thrownBy(fn () => $session->readResponse());

    expect($thrown)->toBeInstanceOf(IcapConnectionException::class);
    expect($thrown?->getPrevious())->toBe($failure);
    m::close();
});

/*
 * A read that returned is looked at before its bytes are used. The timer of a window cannot fire while the event loop
 * is held, and a read that finds its bytes in the socket does not suspend at all, so the end of the window on the
 * monotonic clock decides: at the first bytes (before the window of the transfer takes the place of the one of the
 * start), at the end of the frame, and for the caller's cancellation at the first bytes as well.
 */

/** A valid response with a body of $chunks one-byte chunks: 6,081 bytes for 1,000 of them. */
function phaseChunkedFrame(int $chunks): string
{
    return "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=19\r\n\r\n"
        . "HTTP/1.1 200 OK\r\n\r\n"
        . str_repeat("1\r\nX\r\n", $chunks)
        . "0\r\n\r\n";
}

/**
 * A socket whose reads answer from a script of [microseconds the read holds the event loop, bytes it returns, or null
 * for the end of the stream]. $began counts the reads that were begun.
 *
 * @param list<array{int, string|null}> $reads
 * @param \ArrayObject<string, int>|null $began
 */
function phaseScriptedSocket(array $reads, ?DeferredCancellation $cancelOnRead = null, ?\ArrayObject $began = null): Socket\Socket&\Mockery\MockInterface
{
    $next = $began ?? new ArrayObject(['read' => 0]);
    /** @var Socket\Socket&\Mockery\MockInterface $socket */
    $socket = m::mock(Socket\Socket::class);
    /** @var \Mockery\Expectation $read */
    $read = $socket->shouldReceive('read');
    $read->andReturnUsing(function () use ($reads, $next, $cancelOnRead): ?string {
        [$hold, $bytes] = $reads[$next['read']++] ?? [0, ''];
        usleep($hold);
        $cancelOnRead?->cancel();

        return $bytes;
    });
    /** @var \Mockery\Expectation $close */
    $close = $socket->shouldReceive('close');
    $close->zeroOrMoreTimes();

    return $socket;
}

it('does not return a complete answer that was buffered when the time for its transfer had passed', function () {
    // The stream timeout of the session: ample, or ten microseconds, which decoding 6,081 bytes takes longer than.
    $cases = ['ample' => [5.0, null], 'ten microseconds' => [0.00001, IcapTimeoutException::class]];
    $frame = phaseChunkedFrame(1000);

    foreach ($cases as $case => [$window, $expected]) {
        [$peer, $client] = Socket\createSocketPair();
        $hung = false;
        $watchdog = armWatchdog($client, $peer, $hung);
        // Built by hand: the transport would give the connection setup the same few microseconds.
        $session = new AmpTransportSession(new Config('icap.example', responseStartTimeout: 5.0), $client, $window, null, 1_000_000, 8192, null);

        try {
            // The whole answer is in the socket before the call: the read finds it without waiting, and the frame is
            // complete within that one read. No timer gets a turn of the event loop.
            $peer->write($frame);
            $returned = null;
            $thrown = thrownBy(function () use ($session, &$returned): void {
                $returned = $session->readResponse();
            });

            expect($hung)->toBeFalse($case);
            if ($expected === null) {
                expect($thrown)->toBeNull($case);
                expect($returned)->toBe($frame, $case);
                expect($client->isClosed())->toBeFalse($case);
            } else {
                expect($thrown)->toBeInstanceOf($expected, $case);
                expect($thrown?->getMessage())->toContain('stream timeout');
                expect($client->isClosed())->toBeTrue($case);
            }
        } finally {
            EventLoop::cancel($watchdog);
            $session->close();
            $peer->close();
        }
    }
});

it('does not accept the first bytes of an answer that a read returned after the response start timeout, nor give them a window of the transfer', function () {
    // [microseconds the read holds the event loop, how the call ends]: the start timeout is 30 ms
    $cases = ['in time' => [0, null], 'late' => [90_000, IcapTimeoutException::class]];
    $config = new Config('icap.example', streamTimeout: 5.0, responseStartTimeout: 0.03);

    foreach ($cases as $case => [$hold, $expected]) {
        $session = new AmpTransportSession($config, phaseScriptedSocket([[$hold, PHASE_FRAME]]), 5.0, null, 1_000_000, 8192, null);

        $returned = null;
        $thrown = thrownBy(function () use ($session, &$returned): void {
            $returned = $session->readResponse();
        });

        // The whole answer came with the first read: a window of the transfer that began with it would call it in time.
        if ($expected === null) {
            expect($thrown)->toBeNull($case);
            expect($returned)->toBe(PHASE_FRAME, $case);
        } else {
            expect($thrown)->toBeInstanceOf($expected, $case);
            expect($thrown?->getMessage())->toContain('response start timeout');
        }
    }
    m::close();
});

it('does not accept the rest of an answer that a read returned after the time for its transfer', function () {
    // [microseconds the second read holds the event loop, how the call ends]: the transfer has 40 ms from the first bytes
    $cases = ['in time' => [0, null], 'late' => [90_000, IcapTimeoutException::class]];
    $config = new Config('icap.example', responseStartTimeout: 5.0);

    foreach ($cases as $case => [$hold, $expected]) {
        $socket = phaseScriptedSocket([[0, substr(PHASE_FRAME, 0, 10)], [$hold, substr(PHASE_FRAME, 10)]]);
        $session = new AmpTransportSession($config, $socket, 0.04, null, 1_000_000, 8192, null);

        $returned = null;
        $thrown = thrownBy(function () use ($session, &$returned): void {
            $returned = $session->readResponse();
        });

        // The start timeout (5 s) is far away: the end of the first stretch must not be the one that is looked at.
        if ($expected === null) {
            expect($thrown)->toBeNull($case);
            expect($returned)->toBe(PHASE_FRAME, $case);
        } else {
            expect($thrown)->toBeInstanceOf($expected, $case);
            expect($thrown?->getMessage())->toContain('stream timeout');
        }
    }
    m::close();
});

it('lets the cancellation of the caller win over an answer that a read returned while they cancelled', function () {
    $caller = new DeferredCancellation();
    $socket = phaseScriptedSocket([[0, PHASE_FRAME]], $caller);
    $session = new AmpTransportSession(new Config('icap.example'), $socket, 5.0, $caller->getCancellation(), 1_000_000, 8192, null);

    $thrown = thrownBy(fn () => $session->readResponse());

    expect($thrown)->toBeInstanceOf(CancelledException::class);
    expect($thrown)->not->toBeInstanceOf(IcapTimeoutException::class);
    m::close();
});

/*
 * Every read is looked at, not only the first one and the frame. What a read brought (bytes or the end of the stream)
 * is not handed to the decoder if the caller has cancelled or the time for the transfer has passed. No read is begun
 * after that either.
 */
it('begins no read and accepts no result once the time for the transfer has passed, bytes and the end of the stream alike', function () {
    [$a, $b, $c] = [substr(PHASE_FRAME, 0, 10), substr(PHASE_FRAME, 10, 10), substr(PHASE_FRAME, 20)];
    // [script of [microseconds the read holds the event loop, what it returns], stream timeout, how the call ends, reads begun]
    $cases = [
        'in time'                   => [[[0, $a], [0, $b], [0, $c]], 5.0, PHASE_FRAME, 3],
        'second read late'          => [[[0, $a], [90_000, $b], [150_000, $c]], 0.03, IcapTimeoutException::class, 2],
        'no time for the transfer'  => [[[0, $a], [0, $b], [0, $c]], 0.0, IcapTimeoutException::class, 1],
        'end of the stream in time' => [[[0, $a], [0, null]], 5.0, IcapMalformedResponseException::class, 2],
        'end of the stream late'    => [[[0, $a], [90_000, null]], 0.03, IcapTimeoutException::class, 2],
    ];

    $seen = [];
    foreach ($cases as $case => [$script, $window]) {
        $began = new ArrayObject(['read' => 0]);
        $session = new AmpTransportSession(new Config('icap.example', responseStartTimeout: 5.0), phaseScriptedSocket($script, null, $began), $window, null, 1_000_000, 8192, null);

        $returned = null;
        $thrown = thrownBy(function () use ($session, &$returned): void {
            $returned = $session->readResponse();
        });

        // The start timeout (5 s) is far away: the first bytes were in time, and it is the transfer that ran out.
        if ($thrown instanceof IcapTimeoutException) {
            Assert::assertStringContainsString('stream timeout', $thrown->getMessage(), $case);
        }
        $seen[$case] = [$thrown === null ? $returned : $thrown::class, $began['read']];
    }

    expect($seen)->toBe(array_map(static fn (array $case): array => [$case[2], $case[3]], $cases));
    m::close();
});

it('tells a timeout from a framing error when the peer hangs up on an answer that is buffered whole but for its last chunk', function () {
    // 6,076 bytes of a valid answer, without the final chunk, and a peer that is gone.
    $cut = substr(phaseChunkedFrame(1000), 0, -5);
    // The stream timeout of the session: ample, or ten microseconds, which decoding the bytes takes longer than.
    $cases = ['ample' => [5.0, IcapMalformedResponseException::class], 'ten microseconds' => [0.00001, IcapTimeoutException::class]];

    foreach ($cases as $case => [$window, $expected]) {
        [$peer, $client] = Socket\createSocketPair();
        $hung = false;
        $watchdog = armWatchdog($client, $peer, $hung);
        // Built by hand: the transport would give the connection setup the same few microseconds.
        $session = new AmpTransportSession(new Config('icap.example', responseStartTimeout: 5.0), $client, $window, null, 1_000_000, 8192, null);

        try {
            $peer->write($cut);
            $peer->close();
            $thrown = thrownBy(fn () => $session->readResponse());

            expect($hung)->toBeFalse($case);
            expect($thrown)->toBeInstanceOf($expected, $case);
            expect($client->isClosed())->toBeTrue($case);
        } finally {
            EventLoop::cancel($watchdog);
            $session->close();
            $peer->close();
        }
    }
});

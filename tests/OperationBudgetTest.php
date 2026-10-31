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

namespace Ndrstmr\Icap\Tests;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\TimeoutCancellation;
use ArrayObject;
use LogicException;
use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Cache\OptionsCacheKey;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\OperationDeadline;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\SocketOpener;
use Ndrstmr\Icap\Transport\SynchronousStreamSession;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\TransportSession;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;

use function Amp\async;
use function Amp\delay;

uses(AsyncTestCase::class);

/*
 * AP-12 (M10) — one operation, one end: phases that are each within their limits are still over the operation when
 * they add up, and nothing that arrives after the end is a result. The clients here run against real socket pairs,
 * real listeners and transports that hold the event loop, not against a mock that fails by itself.
 */

const BUDGET_CLEAN = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
const BUDGET_CONTINUE = "ICAP/1.0 100 Continue\r\n\r\n";

function budgetThrown(\Closure $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

/** Reads what the client sends until $until has arrived, or the stream ends, or a safety limit of 5 seconds. */
function budgetReadUntil(Socket\Socket $end, string $until): string
{
    $bytes = '';
    while (!str_contains($bytes, $until)) {
        try {
            $chunk = $end->read(new TimeoutCancellation(5.0));
        } catch (\Throwable) {
            break;
        }
        if ($chunk === null) {
            break;
        }
        $bytes .= $chunk;
    }

    return $bytes;
}

/**
 * Every watcher the event loop holds, by identifier: what is left of a client after it is done.
 *
 * @return list<string>
 */
function budgetWatchers(): array
{
    return array_values(EventLoop::getIdentifiers());
}

/** @return list<string> the timers (delays and repeats) the loop holds */
function budgetTimers(): array
{
    return array_values(array_filter(
        EventLoop::getIdentifiers(),
        static fn (string $id): bool => in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true),
    ));
}

/**
 * A client over one end of a socket pair that a pool hands out; the test plays the server on the other end.
 *
 * @return array{client: IcapClient, server: Socket\Socket, end: Socket\Socket, pool: AmpConnectionPool, connections: ArrayObject<string, int>}
 */
function budgetPooledClient(Config $config, ?InMemoryOptionsCache $cache = null): array
{
    [$server, $end] = Socket\createSocketPair();
    $connections = new ArrayObject(['opened' => 0]);
    $pool = new AmpConnectionPool(connector: static function () use ($end, $connections): Socket\Socket {
        $connections['opened']++;

        return $end;
    });

    return [
        'client' => new IcapClient($config, new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser(), optionsCache: $cache),
        'server' => $server,
        'end' => $end,
        'pool' => $pool,
        'connections' => $connections,
    ];
}

it('ends a preview exchange whose phases are each within their limits but together over the operation timeout', function () {
    // The server needs 0.35 seconds for the preview and 0.35 for the rest: each is far inside the stream timeout (5 s)
    // and the time to begin an answer (5 s), and together they are 0.7 seconds. [operation timeout, the scan ends how]
    $cases = [[0.6, IcapTimeoutException::class], [5.0, null]];

    foreach ($cases as [$budget, $expected]) {
        $path = ScriptedSource::add('budget-preview', str_repeat('A', 100));
        $fx = budgetPooledClient(new Config('icap.example', streamTimeout: 5.0, operationTimeout: $budget));

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($fx, $path, $expected, $budget) {
                $baseline = budgetTimers();
                $serving = async(static function () use ($fx): void {
                    budgetReadUntil($fx['server'], "0\r\n\r\n");
                    delay(0.35);
                    $fx['server']->write(BUDGET_CONTINUE);
                    budgetReadUntil($fx['server'], "0\r\n\r\n");
                    delay(0.35);
                    try {
                        $fx['server']->write(BUDGET_CLEAN);
                    } catch (\Throwable) {
                        // the client has given up
                    }
                });

                $started = hrtime(true);
                $thrown = budgetThrown(fn () => $fx['client']->scanFileWithPreview('/svc', $path, 32)->await());
                $elapsed = (hrtime(true) - $started) / 1e9;
                $serving->await();

                if ($expected === null) {
                    expect($thrown)->toBeNull((string) $budget)
                        ->and($elapsed)->toBeGreaterThanOrEqual(0.65)
                        // A scan that was in time leaves its socket to the pool.
                        ->and($fx['end']->isClosed())->toBeFalse();
                } else {
                    expect($thrown)->toBeInstanceOf($expected, (string) $budget)
                        ->and($elapsed)->toBeGreaterThanOrEqual(0.55)->toBeLessThan(1.5)
                        // The socket is closed, not pooled, and the file handle is closed.
                        ->and($fx['end']->isClosed())->toBeTrue()
                        ->and(ScriptedSource::$opened)->toBe(1)
                        ->and(ScriptedSource::$closed)->toBe(1)
                        ->and($fx['connections']['opened'])->toBe(1);
                }

                $fx['pool']->close();
                expect(budgetTimers())->toBe($baseline);
            });
        } finally {
            ScriptedSource::reset();
            $fx['end']->close();
            $fx['server']->close();
            $fx['pool']->close();
        }
    }
});

it('ends a scan stuck in writing its request at the operation timeout, with the stream timeout far away', function () {
    // Failed futures and exceptions keep their trace arguments, and with them the file handle: it has to be closed regardless.
    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];

    try {
        foreach (['scanFile', 'scanFileWithPreview'] as $method) {
            $before = budgetWatchers();
            $path = ScriptedSource::add('budget-stalled', str_repeat('A', 4 * 1024 * 1024));
            $fx = budgetPooledClient(new Config('icap.example', streamTimeout: 30.0, operationTimeout: 0.4));

            try {
                /** @var AsyncTestCase $this */
                $this->runAsyncTest(function () use ($fx, $path, $method, $before, &$held) {
                    $hung = new ArrayObject(['hung' => false]);
                    $watchdog = EventLoop::delay(3.0, static function () use ($fx, $hung): void {
                        $hung['hung'] = true;
                        $fx['end']->close();
                        $fx['server']->close();
                    });

                    try {
                        // The server reads nothing (for the preview flow: nothing after the 100 Continue): the write is stuck.
                        $serving = $method === 'scanFileWithPreview' ? async(static function () use ($fx): void {
                            budgetReadUntil($fx['server'], "0\r\n\r\n");
                            $fx['server']->write(BUDGET_CONTINUE);
                        }) : null;

                        $started = hrtime(true);
                        $thrown = budgetThrown(fn () => $method === 'scanFile'
                            ? $fx['client']->scanFile('/svc', $path)->await()
                            : $fx['client']->scanFileWithPreview('/svc', $path, 4)->await());
                        $elapsed = (hrtime(true) - $started) / 1e9;
                        $serving?->await();
                        $held[] = $thrown;

                        expect($hung['hung'])->toBeFalse($method)
                            ->and($thrown)->toBeInstanceOf(IcapTimeoutException::class, $method)
                            ->and($elapsed)->toBeGreaterThanOrEqual(0.35)->toBeLessThan(2.0)
                            ->and(ScriptedSource::$opened)->toBe(1)
                            ->and(ScriptedSource::$closed)->toBe(1)
                            ->and($fx['end']->isClosed())->toBeTrue($method);
                    } finally {
                        EventLoop::cancel($watchdog);
                    }

                    // No write fiber is left stuck on the socket, and no timer: once the sockets of the test are closed, the
                    // loop holds what it held before the client was made.
                    $fx['server']->close();
                    $fx['end']->close();
                    $fx['pool']->close();
                    delay(0.05);
                    expect(budgetWatchers())->toBe($before);
                });
            } finally {
                ScriptedSource::reset();
                $fx['end']->close();
                $fx['server']->close();
                $fx['pool']->close();
            }
        }
    } finally {
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
});

it('ends a connect that hangs at the operation timeout, whichever operation asks, and gives back the room', function () {
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 1,
        connector: static function (Config $config, ?Cancellation $cancellation): Socket\Socket {
            delay(8.0, true, $cancellation);

            throw new LogicException('the connection attempt ran to the end');
        },
    );
    $client = new IcapClient(
        new Config('icap.example', streamTimeout: 30.0, operationTimeout: 0.3),
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $pool) {
            $baseline = budgetTimers();
            $path = ScriptedSource::add('budget-connect', 'payload');
            $calls = [
                'options'             => fn () => $client->options('/svc')->await(),
                'request'             => fn () => $client->request(new IcapRequest('REQMOD', 'icap://icap.example/svc'))->await(),
                'scanFile'            => fn () => $client->scanFile('/svc', $path)->await(),
                'scanFileWithPreview' => fn () => $client->scanFileWithPreview('/svc', $path, 4)->await(),
            ];

            $held = [];
            foreach ($calls as $name => $call) {
                $started = hrtime(true);
                $held[$name] = budgetThrown($call);
                $elapsed = (hrtime(true) - $started) / 1e9;

                expect($held[$name])->toBeInstanceOf(IcapTimeoutException::class, $name)
                    ->and($elapsed)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(2.0);
            }

            // The pool admits one connection: every attempt freed the room of the one before, and the files are closed.
            expect($this->privateCount($pool, 'connecting'))->toBe(0)
                ->and(ScriptedSource::$opened)->toBe(ScriptedSource::$closed)
                ->and(budgetTimers())->toBe($baseline);
        });
    } finally {
        ScriptedSource::reset();
        $pool->close();
    }
});

it('ends a TLS handshake that the server never answers at the operation timeout when that is shorter than its own limit', function () {
    $server = Socket\listen('127.0.0.1:0');
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    /** @var ArrayObject<int, Socket\Socket> $accepted */
    $accepted = new ArrayObject();
    $accepting = async(static function () use ($server, $accepted): void {
        while (($peer = $server->accept()) !== null) {
            $accepted[] = $peer;
        }
    });

    try {
        $client = new IcapClient(
            new Config(
                '127.0.0.1',
                $address->getPort(),
                streamTimeout: 30.0,
                tlsContext: new ClientTlsContext('127.0.0.1'),
                operationTimeout: 0.3,
                tlsHandshakeTimeout: 20.0,
            ),
            new AsyncAmpTransport(),
            new RequestFormatter(),
            new ResponseParser(),
        );

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $accepted, $server, $accepting) {
            $baseline = budgetTimers();
            $started = hrtime(true);
            $thrown = budgetThrown(fn () => $client->options('/svc')->await());
            $elapsed = (hrtime(true) - $started) / 1e9;

            // The listener is part of the loop: it ends with the test, or the loop would never run out of work.
            try {
                expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
                    ->and($elapsed)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(3.0)
                    ->and(budgetTimers())->toBe($baseline);

                // The connection is closed: the server reads the hello of the handshake and then the end of the stream.
                delay(0.1);
                expect(count($accepted))->toBe(1);
                $bytes = '';
                foreach ($accepted as $peer) {
                    while (($chunk = $peer->read(new TimeoutCancellation(1.0))) !== null) {
                        $bytes .= $chunk;
                    }
                }
                expect($bytes)->not->toBe('');
            } finally {
                $server->close();
                $accepting->await();
            }
        });
    } finally {
        // A test that failed before it started the loop must not leave the listener to keep the loop alive.
        $server->close();
        $accepting->await();
        foreach ($accepted as $peer) {
            $peer->close();
        }
    }
});

/**
 * A transport that holds the event loop: its answer comes when the operation is over, and the timers had no chance
 * to say so. Sessions and plain requests both.
 */
final class BudgetSlowTransport implements SessionAwareTransport
{
    public int $released = 0;
    public int $closed = 0;
    public int $writes = 0;

    /**
     * @param bool $continueFirst the session answers its first read with 100 Continue at once, and holds only the final answer
     * @param bool $lateContinue  the session holds its first answer as well, and it is a 100 Continue
     */
    public function __construct(private readonly float $holdSeconds, private readonly bool $continueFirst = false, private readonly bool $lateContinue = false)
    {
    }

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        foreach ($rawRequest as $_) {
            // a real transport writes every chunk
        }
        usleep((int) ($this->holdSeconds * 1_000_000));

        return Future::complete(BUDGET_CLEAN);
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession
    {
        $transport = $this;

        return new class ($transport, $this->holdSeconds, $this->continueFirst, $this->lateContinue) implements TransportSession {
            private int $reads = 0;

            public function __construct(private readonly BudgetSlowTransport $transport, private readonly float $holdSeconds, private readonly bool $continueFirst, private readonly bool $lateContinue)
            {
            }

            #[\Override]
            public function write(iterable $chunks): void
            {
                $this->transport->writes++;
                foreach ($chunks as $_) {
                    // a real session writes every chunk
                }
            }

            #[\Override]
            public function readResponse(): string
            {
                $first = $this->reads++ === 0;
                if ($this->continueFirst && $first) {
                    return BUDGET_CONTINUE;
                }
                usleep((int) ($this->holdSeconds * 1_000_000));

                return $this->lateContinue && $first ? BUDGET_CONTINUE : BUDGET_CLEAN;
            }

            #[\Override]
            public function release(): void
            {
                $this->transport->released++;
            }

            #[\Override]
            public function close(): void
            {
                $this->transport->closed++;
            }
        };
    }
}

it('does not return a result that came in after the end of the operation, from whichever operation', function () {
    $transport = new BudgetSlowTransport(0.3);
    $cache = new InMemoryOptionsCache();
    $client = new IcapClient(
        new Config('icap.example', operationTimeout: 0.1),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
        optionsCache: $cache,
    );

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $transport, $cache) {
            $baseline = budgetTimers();
            $path = ScriptedSource::add('budget-late', 'payload');

            // The answer is a clean verdict, in every case; it comes after the end, and is none.
            $held = [
                'request'             => budgetThrown(fn () => $client->request(new IcapRequest('REQMOD', 'icap://icap.example/svc'))->await()),
                'options'             => budgetThrown(fn () => $client->options('/svc')->await()),
                'scanFile'            => budgetThrown(fn () => $client->scanFile('/svc', $path)->await()),
                'scanFileWithPreview' => budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await()),
            ];

            foreach ($held as $name => $thrown) {
                expect($thrown)->toBeInstanceOf(IcapTimeoutException::class, $name);
            }
            // The late OPTIONS answer was not cached, the files were closed, and the session of the strict preview was
            // closed, not given back to the pool: no exchange that was over time is offered to the next scan.
            expect($cache->get(OptionsCacheKey::forService('icap.example', 1344, '/svc')))->toBeNull()
                ->and(ScriptedSource::$opened)->toBe(2)
                ->and(ScriptedSource::$closed)->toBe(2)
                ->and($transport->released)->toBe(0)
                ->and($transport->closed)->toBe(1)
                ->and(budgetTimers())->toBe($baseline);
        });
    } finally {
        ScriptedSource::reset();
    }
});

/**
 * A transport that answers after a delay that it honours the cancellation for, like AsyncAmpTransport does.
 */
final class BudgetDelayTransport implements TransportInterface
{
    /** @param list<float> $delays the delay of the first call, of the second, ... */
    public function __construct(private readonly array $delays)
    {
    }

    public int $calls = 0;

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        $delay = $this->delays[$this->calls++] ?? 0.0;

        /** @var Future<string> $answer */
        $answer = async(static function () use ($delay, $cancellation): string {
            delay($delay, true, $cancellation);

            return BUDGET_CLEAN;
        });

        return $answer;
    }
}

it('gives separate and parallel calls a budget of their own, each from its own start', function () {
    // Call A takes 0.5 seconds and has 0.3. Call B starts at 0.2 and takes 0.05; call C starts at 0.35, after A has
    // ended, and takes 0.25: it would be over a budget that began with A, and is inside its own.
    $client = new IcapClient(
        new Config('icap.example', operationTimeout: 0.3),
        new BudgetDelayTransport([0.5, 0.05, 0.25]),
        new RequestFormatter(),
        new ResponseParser(),
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $baseline = budgetTimers();
        $request = new IcapRequest('REQMOD', 'icap://icap.example/svc');
        $started = hrtime(true);
        $at = static fn (): float => (hrtime(true) - $started) / 1e9;

        /** @var Future<array{\Throwable|null, float}> $a */
        $a = async(function () use ($client, $request, $at): array {
            $thrown = budgetThrown(fn () => $client->request($request)->await());

            return [$thrown, $at()];
        });
        delay(0.2);
        $b = $client->request($request)->await();
        delay(0.15);
        $c = $client->request($request)->await();
        [$thrownA, $endA] = $a->await();

        expect($thrownA)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($endA)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(0.45)
            ->and($b->isInfected())->toBeFalse()
            ->and($c->isInfected())->toBeFalse()
            ->and($at())->toBeGreaterThanOrEqual(0.6)
            ->and(budgetTimers())->toBe($baseline);
    });
});

it('ends a call at the earlier of its own budget and the one of the client that wraps it, whichever is shorter', function () {
    // [budget of the decorator, operation timeout of the client]: the shorter one is the end, and neither extends the other.
    $cases = [[0.3, 60.0], [60.0, 0.3]];

    foreach ($cases as [$outer, $inner]) {
        $fx = budgetPooledClient(new Config('icap.example', streamTimeout: 30.0, operationTimeout: $inner));
        $client = new RetryingIcapClient($fx['client'], operationTimeout: $outer);

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $fx, $outer, $inner) {
                $baseline = budgetTimers();
                $path = ScriptedSource::add('budget-nested', 'payload');
                // The server reads and never answers.
                $reading = async(static fn () => budgetReadUntil($fx['server'], 'ieof'));

                $started = hrtime(true);
                $thrown = budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await());
                $elapsed = (hrtime(true) - $started) / 1e9;

                expect($thrown)->toBeInstanceOf(IcapTimeoutException::class, "{$outer} / {$inner}")
                    ->and($elapsed)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(2.0)
                    ->and($fx['end']->isClosed())->toBeTrue()
                    ->and(ScriptedSource::$closed)->toBe(1);

                $fx['server']->close();
                $reading->await();
                $fx['pool']->close();
                expect(budgetTimers())->toBe($baseline);
            });
        } finally {
            ScriptedSource::reset();
            $fx['end']->close();
            $fx['server']->close();
            $fx['pool']->close();
        }
    }
});

/*
 * N03 — a 5xx proves nothing about the bytes that were sent: no upload is sent a second time, whichever status it was
 * answered with, and the classification of a preview 5xx does not change that.
 */
it('sends a file once, not again, after a 5xx — as a plain scan or in a preview', function () {
    $busy = "ICAP/1.0 503 Service Unavailable\r\n\r\n";

    foreach (['scanFile', 'scanFileWithPreview'] as $method) {
        $transport = new ScriptedTransport([$busy, $busy, $busy]);
        $client = new RetryingIcapClient(
            new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser()),
            maxAttempts: 3,
            baseDelaySeconds: 0.0,
            maxDelaySeconds: 0.0,
        );

        try {
            $path = ScriptedSource::add('budget-once', str_repeat('A', 100));

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $path, $method) {
                $thrown = budgetThrown(fn () => $method === 'scanFile'
                    ? $client->scanFile('/svc', $path)->await()
                    : $client->scanFileWithPreview('/svc', $path, 32)->await());

                // A 503 is a server failure in both flows (the preview used to call it a protocol error).
                expect($thrown)->toBeInstanceOf(IcapServerException::class, $method);
            });

            expect(count($transport->requests))->toBe(1, $method)
                ->and(ScriptedSource::$opened)->toBe(1, $method)
                ->and(ScriptedSource::$closed)->toBe(1, $method);
        } finally {
            ScriptedSource::reset();
        }
    }
});

it('repeats a bodyless OPTIONS after a 503 but not a request that carried a body', function () {
    $busy = "ICAP/1.0 503 Service Unavailable\r\n\r\n";
    $options = "ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\nEncapsulated: null-body=0\r\n\r\n";
    $transport = new ScriptedTransport([$busy, $busy, $options]);
    $client = new RetryingIcapClient(
        new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser()),
        maxAttempts: 3,
        baseDelaySeconds: 0.0,
        maxDelaySeconds: 0.0,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $transport) {
        expect($client->options('/svc')->await()->statusCode)->toBe(200)
            ->and(count($transport->requests))->toBe(3);
    });
});

/**
 * An OPTIONS cache that holds the event loop when it is asked and when it is written, counts both, and can cancel the
 * caller while it is asked.
 */
final class BudgetCache implements OptionsCacheInterface
{
    public int $asked = 0;
    public int $written = 0;

    public function __construct(
        private readonly ?IcapResponse $hit = null,
        private readonly float $getSeconds = 0.0,
        private readonly float $setSeconds = 0.0,
        private readonly ?DeferredCancellation $cancelWhenAsked = null,
    ) {
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $this->asked++;
        usleep((int) ($this->getSeconds * 1_000_000));
        $this->cancelWhenAsked?->cancel();

        return $this->hit;
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $this->written++;
        usleep((int) ($this->setSeconds * 1_000_000));
    }

    #[\Override]
    public function delete(string $key): void
    {
    }
}

final class BudgetRecordingLogger extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> message and context of each record */
    public array $records = [];

    /** @param array<mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $message, $context];
    }
}

it('opens no file for a preview whose size lookup in the cache took the whole budget', function () {
    $cached = new IcapResponse(200, ['Preview' => ['4']]);
    $transport = new ScriptedTransport();
    $client = new IcapClient(new Config('icap.example', operationTimeout: 0.1), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: new BudgetCache($cached, getSeconds: 0.3));

    try {
        $path = ScriptedSource::add('budget-cache', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $transport) {
            expect(budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path)->await()))->toBeInstanceOf(IcapTimeoutException::class)
                ->and(ScriptedSource::$opened)->toBe(0)
                ->and($transport->requests)->toBe([]);
        });
    } finally {
        ScriptedSource::reset();
    }
});

it('logs a call that ran out of time as the timeout it is, and never as completed', function () {
    // A late answer to a request, and a connection that never comes: the class of the failure is IcapTimeoutException.
    $hanging = new AmpConnectionPool(connector: static function (Config $config, ?Cancellation $cancellation): Socket\Socket {
        delay(8.0, true, $cancellation);

        throw new LogicException('the connection attempt ran to the end');
    });
    $cases = [
        'late answer'  => [new IcapClient(new Config('icap.example', operationTimeout: 0.1), new BudgetSlowTransport(0.3), new RequestFormatter(), new ResponseParser(), logger: $late = new BudgetRecordingLogger())],
        'no connection' => [new IcapClient(new Config('icap.example', streamTimeout: 30.0, operationTimeout: 0.2), new AsyncAmpTransport($hanging), new RequestFormatter(), new ResponseParser(), logger: $none = new BudgetRecordingLogger())],
    ];

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($cases, $late, $none) {
            foreach ($cases as $case => [$client]) {
                expect(budgetThrown(fn () => $client->request(new IcapRequest('REQMOD', 'icap://icap.example/svc'))->await()))
                    ->toBeInstanceOf(IcapTimeoutException::class, $case);
            }

            foreach (['late answer' => $late, 'no connection' => $none] as $case => $logger) {
                $messages = array_map(static fn (array $record): string => $record[0], $logger->records);
                $failure = $logger->records[1][1] ?? [];

                expect($messages)->toBe(['ICAP request started', 'ICAP request failed'], $case)
                    ->and($failure['exception_class'] ?? null)->toBe(IcapTimeoutException::class, $case);
            }
        });
    } finally {
        $hanging->close();
    }
});

it('closes the session and gives it to nobody when the final answer of a preview comes in after the end', function () {
    $transport = new BudgetSlowTransport(0.3, continueFirst: true);
    $client = new IcapClient(new Config('icap.example', operationTimeout: 0.15), $transport, new RequestFormatter(), new ResponseParser());

    try {
        $path = ScriptedSource::add('budget-final', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $transport) {
            // 100 Continue at once, the continuation is sent, and the verdict is clean - 0.3 seconds after the end.
            expect(budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await()))->toBeInstanceOf(IcapTimeoutException::class)
                ->and($transport->released)->toBe(0)
                ->and($transport->closed)->toBe(1)
                ->and(ScriptedSource::$closed)->toBe(1);
        });
    } finally {
        ScriptedSource::reset();
    }
});

it('sends no continuation when the 100 Continue came in after the end of the operation', function () {
    $transport = new BudgetSlowTransport(0.3, lateContinue: true);
    $client = new IcapClient(new Config('icap.example', operationTimeout: 0.15), $transport, new RequestFormatter(), new ResponseParser());

    try {
        $path = ScriptedSource::add('budget-late-continue', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $transport) {
            // The server said 100 Continue, late: the rest of the file is not sent on the strength of that.
            expect(budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await()))->toBeInstanceOf(IcapTimeoutException::class)
                ->and($transport->writes)->toBe(1)
                ->and($transport->released)->toBe(0)
                ->and($transport->closed)->toBe(1)
                ->and(ScriptedSource::$closed)->toBe(1);
        });
    } finally {
        ScriptedSource::reset();
    }
});

it('logs the failure of a nested request as the timeout it is', function () {
    // scanFile() makes a request() of its own, inside its operation. When the operation runs out, that request
    // fails with a timeout, not with a cancellation, and says so in its log.
    $hanging = new AmpConnectionPool(connector: static function (Config $config, ?Cancellation $cancellation): Socket\Socket {
        delay(8.0, true, $cancellation);

        throw new LogicException('the connection attempt ran to the end');
    });
    $logger = new BudgetRecordingLogger();
    $client = new IcapClient(new Config('icap.example', streamTimeout: 30.0, operationTimeout: 0.2), new AsyncAmpTransport($hanging), new RequestFormatter(), new ResponseParser(), logger: $logger);

    try {
        $path = ScriptedSource::add('budget-nested-log', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $logger) {
            expect(budgetThrown(fn () => $client->scanFile('/svc', $path)->await()))->toBeInstanceOf(IcapTimeoutException::class);

            $failure = $logger->records[1][1] ?? [];
            expect(array_map(static fn (array $record): string => $record[0], $logger->records))->toBe(['ICAP request started', 'ICAP request failed'])
                ->and($failure['exception_class'] ?? null)->toBe(IcapTimeoutException::class);
        });
    } finally {
        ScriptedSource::reset();
        $hanging->close();
    }
});

/** A cancellation the test can request, that counts what is subscribed to it and what is given back. */
final class BudgetProbeCancellation implements Cancellation
{
    public int $active = 0;
    public bool $requested = false;
    private int $next = 0;
    /** @var array<string, \Closure> */
    private array $callbacks = [];

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $id = 'p' . ++$this->next;
        $this->callbacks[$id] = $callback;
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
        return $this->requested;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        if ($this->requested) {
            throw new CancelledException();
        }
    }
}

it('ends a TLS handshake at the limit of its own, leaves nothing subscribed, and lets the caller win when both apply', function () {
    $server = Socket\listen('127.0.0.1:0');
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    /** @var ArrayObject<int, Socket\Socket> $accepted */
    $accepted = new ArrayObject();
    $accepting = async(static function () use ($server, $accepted): void {
        while (($peer = $server->accept()) !== null) {
            $accepted[] = $peer;
        }
    });

    try {
        $config = new Config('127.0.0.1', $address->getPort(), tlsContext: new ClientTlsContext('127.0.0.1'), tlsHandshakeTimeout: 0.25);

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($config, $server, $accepting) {
            try {
                // [the caller cancels during the handshake, what ends it]
                foreach ([[false, IcapTimeoutException::class], [true, CancelledException::class]] as [$callerCancels, $expected]) {
                    $probe = new BudgetProbeCancellation();
                    // The probe is told nothing: the handshake runs into its own limit (0.25 s) at a time the caller
                    // has cancelled (0.1 s) or has not.
                    $cancelling = $callerCancels ? EventLoop::delay(0.1, static function () use ($probe): void {
                        $probe->requested = true;
                    }) : null;

                    try {
                        $thrown = budgetThrown(fn () => SocketOpener::open($config, $probe));
                    } finally {
                        if ($cancelling !== null) {
                            EventLoop::cancel($cancelling);
                        }
                    }

                    expect($thrown)->toBeInstanceOf($expected, (string) $callerCancels)
                        ->and($probe->active)->toBe(0, (string) $callerCancels);
                }
            } finally {
                $server->close();
                $accepting->await();
            }
        });
    } finally {
        $server->close();
        $accepting->await();
        foreach ($accepted as $peer) {
            $peer->close();
        }
    }
});

it('gives a synchronous connection no more time than is left of the operation', function () {
    $connectTimeout = new ReflectionMethod(SynchronousStreamSession::class, 'connectTimeout');
    $config = new Config('127.0.0.1', socketTimeout: 5.0);

    $over = OperationDeadline::begin(null, 0.001);
    usleep(5_000);
    $short = $connectTimeout->invoke(null, $config, OperationDeadline::begin(null, 0.2));

    // No operation: the setting. A long operation: the setting. A short one: what is left of it and 10 ms, so that a
    // connect it cuts short fails after its end. One that is over: those 10 ms, not 0, which PHP does not take for a
    // timeout.
    expect($connectTimeout->invoke(null, $config, null))->toBe(5.0)
        ->and($connectTimeout->invoke(null, $config, OperationDeadline::begin(null, 60.0)))->toBe(5.0)
        ->and($short)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(0.21)
        ->and($connectTimeout->invoke(null, $config, $over))->toBe(0.01);
});

/*
 * Foreign code gets control: the OPTIONS cache, the logger, the preview strategy. What comes back from it is looked
 * at before anything is answered, written or given away; the caller's cancellation wins over the end of the operation.
 */

/** A logger that holds the event loop when the answer is logged, and lets the test cancel the caller meanwhile. */
final class BudgetSlowLogger extends AbstractLogger
{
    public function __construct(
        private readonly float $holdSeconds,
        private readonly ?DeferredCancellation $cancelWhenCompleted = null,
    ) {
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((string) $message === 'ICAP request completed') {
            usleep((int) ($this->holdSeconds * 1_000_000));
            $this->cancelWhenCompleted?->cancel();
        }
    }
}

/** A preview strategy that holds the event loop while it decides, and lets the test cancel the caller meanwhile. */
final class BudgetSlowStrategy implements PreviewStrategyInterface
{
    public int $asked = 0;

    public function __construct(
        private readonly float $holdSeconds,
        private readonly PreviewDecision $decision,
        private readonly ?DeferredCancellation $cancelWhenAsked = null,
    ) {
    }

    #[\Override]
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        $this->asked++;
        usleep((int) ($this->holdSeconds * 1_000_000));
        $this->cancelWhenAsked?->cancel();

        return $this->decision;
    }
}

it('answers an OPTIONS call from the cache only while it is wanted and in time, and fails its Future when it is not', function () {
    $hit = new IcapResponse(200, ['Preview' => ['4']]);
    $over = OperationDeadline::begin(null, 0.001);
    usleep(5_000);

    // [the caller has cancelled before the call, cancels while the cache is asked, seconds the cache holds the event
    // loop, operation timeout, the call belongs to an operation that is over, how it ends, how often the cache is asked]
    $cases = [
        'in time, a little slow'             => [false, false, 0.05, 5.0, false, null, 1],
        'cancelled beforehand'               => [true, false, 0.0, 5.0, false, CancelledException::class, 0],
        'cancelled while the cache is asked' => [false, true, 0.0, 5.0, false, CancelledException::class, 1],
        'over time while the cache is asked' => [false, false, 0.2, 0.1, false, IcapTimeoutException::class, 1],
        'cancelled, and over time as well'   => [false, true, 0.2, 0.1, false, CancelledException::class, 1],
        'inside an operation that is over'   => [false, false, 0.0, 5.0, true, IcapTimeoutException::class, 0],
    ];

    foreach ($cases as $case => [$before, $during, $hold, $budget, $inside, $expected, $asked]) {
        $caller = new DeferredCancellation();
        if ($before) {
            $caller->cancel();
        }
        $cache = new BudgetCache($hit, $hold, cancelWhenAsked: $during ? $caller : null);
        $transport = new ScriptedTransport();
        $client = new IcapClient(new Config('icap.example', operationTimeout: $budget), $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $caller, $over, $inside, $expected, $asked, $hit, $cache, $transport, $case) {
            $baseline = budgetTimers();
            $future = null;
            // The call itself does not throw: what ended it is the Future's.
            $thrown = budgetThrown(function () use ($client, $caller, $over, $inside, &$future): void {
                $future = $client->options('/svc', $inside ? $over : $caller->getCancellation());
            });
            $answer = null;
            $failure = budgetThrown(function () use ($future, &$answer): void {
                $answer = $future?->await();
            });

            expect($thrown)->toBeNull($case);
            if ($expected === null) {
                expect($failure)->toBeNull($case)
                    ->and($answer)->toBe($hit, $case);
            } else {
                expect($failure)->toBeInstanceOf($expected, $case)
                    ->and($answer)->toBeNull($case);
            }
            expect($cache->asked)->toBe($asked, $case)
                ->and($transport->requests)->toBe([], $case)
                ->and(budgetTimers())->toBe($baseline, $case);
        });
    }
});

it('writes the OPTIONS answer to the cache only for a call that is wanted and in time, and fails one whose write ran past the end', function () {
    $options = "ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\nOptions-TTL: 60\r\nEncapsulated: null-body=0\r\n\r\n";

    // [seconds the logger holds the event loop, it cancels the caller, seconds the write takes, operation timeout, how
    // the call ends, writes to the cache begun]
    $cases = [
        'in time'                     => [0.0, false, 0.0, 5.0, null, 1],
        'over time'                   => [0.2, false, 0.0, 0.1, IcapTimeoutException::class, 0],
        'cancelled'                   => [0.0, true, 0.0, 5.0, CancelledException::class, 0],
        'cancelled and over time'     => [0.2, true, 0.0, 0.1, CancelledException::class, 0],
        'the write runs past the end' => [0.0, false, 0.3, 0.15, IcapTimeoutException::class, 1],
    ];

    foreach ($cases as $case => [$hold, $cancels, $writeSeconds, $budget, $expected, $writes]) {
        $caller = new DeferredCancellation();
        $cache = new BudgetCache(setSeconds: $writeSeconds);
        $client = new IcapClient(
            new Config('icap.example', operationTimeout: $budget),
            new ScriptedTransport([$options]),
            new RequestFormatter(),
            new ResponseParser(),
            logger: new BudgetSlowLogger($hold, $cancels ? $caller : null),
            optionsCache: $cache,
        );

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $caller, $expected, $writes, $cache, $case) {
            $failure = budgetThrown(fn () => $client->options('/svc', $caller->getCancellation())->await());

            // The answer was in time, and the cache is written only for a call that is still wanted and in time.
            if ($expected === null) {
                expect($failure)->toBeNull($case);
            } else {
                expect($failure)->toBeInstanceOf($expected, $case);
            }
            expect($cache->written)->toBe($writes, $case);
        });
    }
});

it('closes the socket of a preview whose strategy decided after the end of the operation, instead of leaving it idle in the pool', function () {
    // [seconds the strategy holds the event loop, operation timeout, how the call ends, the socket is closed]
    $cases = [
        'in time'      => [0.0, 5.0, null, false],
        'past the end' => [0.3, 0.15, IcapTimeoutException::class, true],
    ];

    foreach ($cases as $case => [$hold, $budget, $expected, $closed]) {
        $path = ScriptedSource::add('budget-strategy', 'payload');
        $config = new Config('icap.example', streamTimeout: 5.0, operationTimeout: $budget);
        $fx = budgetPooledClient($config);
        $client = new IcapClient($config, new AsyncAmpTransport($fx['pool']), new RequestFormatter(), new ResponseParser(), new BudgetSlowStrategy($hold, PreviewDecision::ABORT_CLEAN));

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($fx, $client, $path, $expected, $closed, $case) {
                // The server answers the preview with 204 at once: the exchange is clean and in time, only the strategy is late.
                $serving = async(static function () use ($fx): void {
                    budgetReadUntil($fx['server'], "0\r\n\r\n");
                    try {
                        $fx['server']->write(BUDGET_CLEAN);
                    } catch (\Throwable) {
                        // the client has given up
                    }
                });
                $thrown = budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await());
                $serving->await();

                if ($expected === null) {
                    expect($thrown)->toBeNull($case);
                } else {
                    expect($thrown)->toBeInstanceOf($expected, $case);
                }
                // A scan that was in time leaves its socket to the pool; one that was not leaves it to nobody.
                expect($fx['end']->isClosed())->toBe($closed, $case)
                    ->and(ScriptedSource::$closed)->toBe(1, $case);
            });
        } finally {
            ScriptedSource::reset();
            $fx['end']->close();
            $fx['server']->close();
            $fx['pool']->close();
        }
    }
});

it('does not call release() on a session whose operation was over or cancelled when the strategy of a preview decided', function () {
    // [seconds the strategy holds the event loop, it cancels the caller, operation timeout, how the call ends, released, closed]
    $cases = [
        'in time'                   => [0.0, false, 5.0, null, 1, 0],
        'past the end'              => [0.3, false, 0.15, IcapTimeoutException::class, 0, 1],
        'cancelled'                 => [0.0, true, 5.0, CancelledException::class, 0, 1],
        'cancelled and past the end' => [0.3, true, 0.15, CancelledException::class, 0, 1],
    ];

    foreach ($cases as $case => [$hold, $cancels, $budget, $expected, $released, $closed]) {
        $caller = new DeferredCancellation();
        // The session answers the preview with 204 at once; a transport of another kind need not guard its release().
        $transport = new BudgetSlowTransport(0.0);
        $client = new IcapClient(
            new Config('icap.example', operationTimeout: $budget),
            $transport,
            new RequestFormatter(),
            new ResponseParser(),
            new BudgetSlowStrategy($hold, PreviewDecision::ABORT_CLEAN, $cancels ? $caller : null),
        );

        try {
            $path = ScriptedSource::add('budget-release', 'payload');

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $caller, $path, $expected, $released, $closed, $transport, $case) {
                $thrown = budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4, [], $caller->getCancellation())->await());

                if ($expected === null) {
                    expect($thrown)->toBeNull($case);
                } else {
                    expect($thrown)->toBeInstanceOf($expected, $case);
                }
                expect($transport->released)->toBe($released, $case)
                    ->and($transport->closed)->toBe($closed, $case)
                    ->and(ScriptedSource::$closed)->toBe(1, $case);
            });
        } finally {
            ScriptedSource::reset();
        }
    }
});

it('sends no continuation when the strategy decided on the 100 Continue after the end of the operation', function () {
    $transport = new BudgetSlowTransport(0.0, continueFirst: true);
    $client = new IcapClient(
        new Config('icap.example', operationTimeout: 0.15),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
        new BudgetSlowStrategy(0.3, PreviewDecision::CONTINUE_SENDING),
    );

    try {
        $path = ScriptedSource::add('budget-strategy-continue', 'payload');

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $path, $transport) {
            // The answer to the preview came in time and said 100 Continue; the strategy said so after the end.
            expect(budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await()))->toBeInstanceOf(IcapTimeoutException::class)
                ->and($transport->writes)->toBe(1)
                ->and($transport->released)->toBe(0)
                ->and($transport->closed)->toBe(1)
                ->and(ScriptedSource::$closed)->toBe(1);
        });
    } finally {
        ScriptedSource::reset();
    }
});

it('does not ask the strategy about a preview answer that came in after the end of the operation', function () {
    // The answer to the preview comes 0.3 s after the operation (0.15 s) ended: clean, or 100 Continue. The strategy
    // would decide on it at once; nothing is decided on an answer that is none.
    $config = new Config('icap.example', operationTimeout: 0.15);
    $cases = [
        'clean'    => [new BudgetSlowTransport(0.3), new BudgetSlowStrategy(0.0, PreviewDecision::ABORT_CLEAN)],
        'continue' => [new BudgetSlowTransport(0.3, lateContinue: true), new BudgetSlowStrategy(0.0, PreviewDecision::CONTINUE_SENDING)],
    ];

    foreach ($cases as $case => [$transport, $strategy]) {
        $client = new IcapClient($config, $transport, new RequestFormatter(), new ResponseParser(), $strategy);

        try {
            $path = ScriptedSource::add('budget-late-answer', 'payload');

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $strategy, $transport, $path, $case) {
                expect(budgetThrown(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await()))->toBeInstanceOf(IcapTimeoutException::class, $case)
                    ->and($strategy->asked)->toBe(0, $case)
                    // No session was given back, and no continuation sent.
                    ->and($transport->released)->toBe(0, $case)
                    ->and($transport->closed)->toBe(1, $case)
                    ->and($transport->writes)->toBe(1, $case)
                    ->and(ScriptedSource::$closed)->toBe(1, $case);
            });
        } finally {
            ScriptedSource::reset();
        }
    }
});

it('does not give the socket of a session back to the pool once its operation is over or was cancelled', function () {
    // [the operation runs out, the caller cancels, the socket goes back to the pool]
    $cases = [
        'in time'        => [false, false, true],
        'past the end'   => [true, false, false],
        'cancelled'      => [false, true, false],
    ];

    foreach ($cases as $case => [$runsOut, $cancels, $pooled]) {
        [$server, $end] = Socket\createSocketPair();
        $pool = new AmpConnectionPool(connector: static fn (): Socket\Socket => $end);
        $caller = new DeferredCancellation();
        $deadline = OperationDeadline::begin($caller->getCancellation(), 0.05);

        try {
            $session = (new AsyncAmpTransport($pool))->openSession(new Config('icap.example'), $deadline);
            if ($runsOut) {
                usleep(80_000);
            }
            if ($cancels) {
                $caller->cancel();
            }
            $session->release();

            expect($end->isClosed())->toBe(!$pooled, $case);
        } finally {
            $deadline->close();
            $end->close();
            $server->close();
            $pool->close();
        }
    }
});

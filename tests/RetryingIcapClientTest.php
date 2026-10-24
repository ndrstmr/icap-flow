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
use Amp\Future;
use Mockery as m;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapClientInterface;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

uses(AsyncTestCase::class);

/**
 * RetryingIcapClient is a decorator that retries 5xx responses with
 * exponential backoff. 4xx and other failure types propagate
 * immediately — fail-secure on client-side errors, retry-on-transient
 * for server-side errors.
 */

it('retries on 503 and returns the eventual success', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    $clean = new IcapResponse(200);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(3);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('busy', 503)),
        \Amp\Future::error(new IcapServerException('still busy', 503)),
        \Amp\Future::complete($clean),
    );

    $client = new RetryingIcapClient($inner, maxAttempts: 3, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $clean) {
        $res = $client->options('/svc')->await();
        expect($res)->toBe($clean);
    });

    m::close();
});

it('gives up after maxAttempts and rethrows the last server exception', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(3);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('one', 503)),
        \Amp\Future::error(new IcapServerException('two', 503)),
        \Amp\Future::error(new IcapServerException('three', 503)),
    );

    $client = new RetryingIcapClient($inner, maxAttempts: 3, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        try {
            $client->options('/svc')->await();
            expect(false)->toBeTrue('expected IcapServerException');
        } catch (IcapServerException $e) {
            expect($e->getMessage())->toBe('three');
        }
    });

    m::close();
});

it('does NOT retry on 4xx — those are caller errors, not transient', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->once();
    $exp->andReturn(\Amp\Future::error(new IcapClientException('bad request', 400)));

    $client = new RetryingIcapClient($inner, maxAttempts: 5, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())
            ->toThrow(IcapClientException::class);
    });

    m::close();
});

it('uses exponential backoff (2x by default) capped by maxDelaySeconds', function () {
    $delays = [];
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    // Full jitter takes a random share of each delay; a share of 1 shows the delays themselves.
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 4,
        baseDelaySeconds: 0.1,
        maxDelaySeconds: 0.25,
        backoffFactor: 2.0,
        sleeper: function (float $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
        random: static fn (): float => 1.0,
    );

    /** @var \Mockery\Expectation $exp */
    $exp = $inner->shouldReceive('options');
    $exp->times(4);
    $exp->andReturn(
        \Amp\Future::error(new IcapServerException('1', 503)),
        \Amp\Future::error(new IcapServerException('2', 503)),
        \Amp\Future::error(new IcapServerException('3', 503)),
        \Amp\Future::complete(new IcapResponse(200)),
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/svc')->await();
    });

    // After 1st failure: 0.1s, after 2nd: 0.2s, after 3rd: capped at 0.25s.
    expect($delays)->toBe([0.1, 0.2, 0.25]);

    m::close();
});

it('forwards request, scanFile and scanFileWithPreview through the decorator', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    $clean = new ScanResult(false, null, new IcapResponse(204));
    $req = new \Ndrstmr\Icap\DTO\IcapRequest('OPTIONS', 'icap://x/svc');

    /** @var \Mockery\Expectation $r1 */
    $r1 = $inner->shouldReceive('request');
    $r1->once();
    $r1->andReturn(\Amp\Future::complete($clean));
    /** @var \Mockery\Expectation $r2 */
    $r2 = $inner->shouldReceive('scanFile');
    $r2->once();
    $r2->andReturn(\Amp\Future::complete($clean));
    /** @var \Mockery\Expectation $r3 */
    $r3 = $inner->shouldReceive('scanFileWithPreview');
    $r3->once();
    $r3->andReturn(\Amp\Future::complete($clean));

    $client = new RetryingIcapClient($inner);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $req) {
        $client->request($req)->await();
        $client->scanFile('/svc', '/tmp/x')->await();
        $client->scanFileWithPreview('/svc', '/tmp/x')->await();
    });

    m::close();
    expect(true)->toBeTrue(); // Mockery verified; satisfy PHPUnit strict-mode
});

it('rejects nonsensical configuration', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);

    expect(fn () => new RetryingIcapClient($inner, maxAttempts: 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RetryingIcapClient($inner, baseDelaySeconds: -1.0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RetryingIcapClient($inner, backoffFactor: 0.0))
        ->toThrow(InvalidArgumentException::class);
});

/*
 * AP-12 (M10, N03). The decorator repeats nothing but options(), pauses a random share of its backoff, ends a pause
 * with the caller's cancellation and spends one budget on all of it.
 */

/**
 * An inner client that answers from a script and counts what it is asked. Its answers are Futures of whatever the
 * script returns; the types are the script's business.
 */
final class RetryScriptedClient implements IcapClientInterface
{
    public int $calls = 0;

    /** @var list<Cancellation|null> */
    public array $cancellations = [];

    /** @param \Closure(int, Cancellation|null): Future<mixed> $answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        /** @var Future<ScanResult> */
        return $this->next($cancellation);
    }

    #[\Override]
    public function options(string $service, ?Cancellation $cancellation = null): Future
    {
        /** @var Future<IcapResponse> */
        return $this->next($cancellation);
    }

    #[\Override]
    public function scanFile(string $service, string $filePath, array $extraHeaders = [], ?Cancellation $cancellation = null): Future
    {
        /** @var Future<ScanResult> */
        return $this->next($cancellation);
    }

    #[\Override]
    public function scanFileWithPreview(string $service, string $filePath, ?int $previewSize = null, array $extraHeaders = [], ?Cancellation $cancellation = null): Future
    {
        /** @var Future<ScanResult> */
        return $this->next($cancellation);
    }

    /** @return Future<mixed> */
    private function next(?Cancellation $cancellation): Future
    {
        $this->cancellations[] = $cancellation;

        return ($this->answer)(++$this->calls, $cancellation);
    }
}

/** What a fiber that counts the turns of the event loop has seen. */
final class RetryTicks
{
    public bool $done = false;
    public int $ticks = 0;
}

/** An inner client whose every answer is a 503. */
function retryBusyClient(): RetryScriptedClient
{
    return new RetryScriptedClient(static fn (int $call): Future => Future::error(new IcapServerException('busy ' . $call, 503)));
}

function retryThrown(\Closure $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

it('repeats nothing but options(): request, scanFile and scanFileWithPreview get one attempt, also after a 5xx', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);
    foreach (['request', 'scanFile', 'scanFileWithPreview'] as $method) {
        /** @var \Mockery\Expectation $expectation */
        $expectation = $inner->shouldReceive($method);
        $expectation->once();
        $expectation->andReturnUsing(static fn (): Future => Future::error(new IcapServerException('busy', 503)));
    }
    $client = new RetryingIcapClient($inner, maxAttempts: 5, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->request(new IcapRequest('REQMOD', 'icap://x/svc'))->await())->toThrow(IcapServerException::class);
        expect(fn () => $client->scanFile('/svc', '/tmp/x')->await())->toThrow(IcapServerException::class);
        expect(fn () => $client->scanFileWithPreview('/svc', '/tmp/x')->await())->toThrow(IcapServerException::class);
    });

    // Mockery counts: one call each, not five.
    m::close();
    expect(true)->toBeTrue();
});

it('pauses a random share of the capped exponential delay, from nothing up to all of it', function () {
    $pauses = [];
    $draws = [0.0, 0.5, 1.0];
    $inner = retryBusyClient();
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 4,
        baseDelaySeconds: 0.1,
        maxDelaySeconds: 0.25,
        backoffFactor: 2.0,
        sleeper: function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        },
        random: function () use (&$draws): float {
            return array_shift($draws) ?? 1.0;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(IcapServerException::class, 'busy 4');
    });

    // The delays 0.1, 0.2 and 0.25 (capped), each taken by 0, a half and all of it.
    expect($pauses)->toBe([0.0, 0.1, 0.25])
        ->and($inner->calls)->toBe(4);
});

it('does not retry in step: by default the pauses differ from each other and stay under the cap', function () {
    $pauses = [];
    $client = new RetryingIcapClient(
        retryBusyClient(),
        maxAttempts: 41,
        baseDelaySeconds: 1.0,
        maxDelaySeconds: 1.0,
        backoffFactor: 1.0,
        sleeper: function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(IcapServerException::class);
    });

    expect($pauses)->toHaveCount(40)
        ->and(max([0.0, ...$pauses]))->toBeLessThanOrEqual(1.0)
        ->and(min([1.0, ...$pauses]))->toBeGreaterThanOrEqual(0.0)
        // Forty draws that are all alike would be the old behaviour: every client waits the same time.
        ->and(count(array_unique($pauses)))->toBeGreaterThan(20);
});

it('saturates the exponential backoff instead of overflowing it into INF or NAN', function () {
    // base, factor, cap, the pause a client that has retried for ever arrives at
    $cases = [
        'the power overflows'       => [1.0, 2.0, 5.0, 5.0],
        'a huge factor'             => [1.0, 1.0e300, 5.0, 5.0],
        'a base of 0, a huge power' => [0.0, 1.0e300, 5.0, 0.0],
        'a cap of 0'                => [1.0, 2.0, 0.0, 0.0],
    ];

    foreach ($cases as $case => [$base, $factor, $cap, $last]) {
        $pauses = [];
        $client = new RetryingIcapClient(
            retryBusyClient(),
            maxAttempts: 1200,
            baseDelaySeconds: $base,
            maxDelaySeconds: $cap,
            backoffFactor: $factor,
            sleeper: function (float $seconds) use (&$pauses): void {
                $pauses[] = $seconds;
            },
            random: static fn (): float => 1.0,
            operationTimeout: 3600.0,
        );

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client) {
            expect(fn () => $client->options('/svc')->await())->toThrow(IcapServerException::class);
        });

        $finite = array_filter($pauses, static fn (float $pause): bool => is_finite($pause) && $pause >= 0.0 && $pause <= $cap);
        expect(count($finite))->toBe(1199, $case)
            ->and(end($pauses))->toBe($last, $case);
    }
});

it('refuses retry parameters that are not finite or not above what they need to be', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $inner */
    $inner = m::mock(IcapClientInterface::class);
    $cases = [
        'base delay NAN'          => fn () => new RetryingIcapClient($inner, baseDelaySeconds: NAN),
        'base delay INF'          => fn () => new RetryingIcapClient($inner, baseDelaySeconds: INF),
        'negative base delay'     => fn () => new RetryingIcapClient($inner, baseDelaySeconds: -0.1),
        'max delay NAN'           => fn () => new RetryingIcapClient($inner, maxDelaySeconds: NAN),
        'max delay INF'           => fn () => new RetryingIcapClient($inner, maxDelaySeconds: INF),
        'negative max delay'      => fn () => new RetryingIcapClient($inner, maxDelaySeconds: -1.0),
        'backoff factor NAN'      => fn () => new RetryingIcapClient($inner, backoffFactor: NAN),
        'backoff factor INF'      => fn () => new RetryingIcapClient($inner, backoffFactor: INF),
        'backoff factor 0'        => fn () => new RetryingIcapClient($inner, backoffFactor: 0.0),
        'operation timeout 0'     => fn () => new RetryingIcapClient($inner, operationTimeout: 0.0),
        'negative operation timeout' => fn () => new RetryingIcapClient($inner, operationTimeout: -5.0),
        'operation timeout NAN'   => fn () => new RetryingIcapClient($inner, operationTimeout: NAN),
        'operation timeout INF'   => fn () => new RetryingIcapClient($inner, operationTimeout: INF),
    ];

    foreach ($cases as $case => $build) {
        expect($build)->toThrow(InvalidArgumentException::class, '', $case);
    }
    expect(new RetryingIcapClient($inner, operationTimeout: 0.001))->toBeInstanceOf(RetryingIcapClient::class);
});

it('spends one budget on all attempts and pauses and ends with a timeout that names the last 5xx', function () {
    $inner = retryBusyClient();
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 100,
        baseDelaySeconds: 0.2,
        maxDelaySeconds: 0.2,
        backoffFactor: 1.0,
        operationTimeout: 0.5,
        random: static fn (): float => 1.0,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $inner) {
        $baseline = $this->loopTimers();
        $started = hrtime(true);
        $thrown = retryThrown(fn () => $client->options('/svc')->await());
        $elapsed = (hrtime(true) - $started) / 1e9;

        // Attempts at 0, 0.2 and 0.4 seconds; the pause after the third runs into the end of the budget at 0.5.
        // A budget that began again with every attempt would run for 100 attempts.
        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(IcapServerException::class)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.45)->toBeLessThan(1.5)
            ->and($inner->calls)->toBe(3)
            // The timer and the subscription of the budget are gone, with the exception still held.
            ->and($this->loopTimers())->toBe($baseline);
    });
});

it('ends a pause between attempts with the cancellation of the caller at once, and tries no more', function () {
    $inner = retryBusyClient();
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 3,
        baseDelaySeconds: 2.0,
        maxDelaySeconds: 2.0,
        random: static fn (): float => 1.0,
    );
    $cancel = new DeferredCancellation();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $inner, $cancel) {
        $baseline = $this->loopTimers();
        $cancelling = EventLoop::delay(0.1, static fn () => $cancel->cancel());

        try {
            $started = hrtime(true);
            $thrown = retryThrown(fn () => $client->options('/svc', $cancel->getCancellation())->await());
            $elapsed = (hrtime(true) - $started) / 1e9;
        } finally {
            EventLoop::cancel($cancelling);
        }

        // Not the end of the pause (2 s), and not a timeout: the caller's cancellation stays what it is.
        expect($thrown)->toBeInstanceOf(CancelledException::class)
            ->and($elapsed)->toBeLessThan(1.0)
            ->and($inner->calls)->toBe(1)
            ->and($this->loopTimers())->toBe($baseline);
    });
});

it('hands the event loop over during every pause, also one of 0 seconds', function () {
    $client = new RetryingIcapClient(retryBusyClient(), maxAttempts: 30, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $state = new RetryTicks();
        $ticker = async(static function () use ($state): void {
            while (!$state->done) {
                delay(0);
                $state->ticks++;
            }
        });

        try {
            $client->options('/svc')->await();
        } catch (IcapServerException) {
            // 30 attempts, all busy
        }
        $state->done = true;
        $ticker->await();

        // A loop of attempts that never suspends would starve the timers and the other fibers of the process.
        expect($state->ticks)->toBeGreaterThan(10);
    });
});

it('looks at the budget before and after a sleeper of the caller, which it cannot interrupt', function () {
    $inner = retryBusyClient();
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 5,
        baseDelaySeconds: 0.01,
        maxDelaySeconds: 0.01,
        sleeper: static function (float $seconds): void {
            usleep(300_000); // holds the event loop for longer than the whole budget
        },
        operationTimeout: 0.15,
        random: static fn (): float => 1.0,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $inner) {
        $thrown = retryThrown(fn () => $client->options('/svc')->await());

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(IcapServerException::class)
            // The attempt after the sleeper was not made.
            ->and($inner->calls)->toBe(1);
    });
});

it('gives every operation the budget of the call, and its inner client the end of it as its cancellation', function () {
    $hanging = new RetryScriptedClient(static function (int $call, ?Cancellation $cancellation): Future {
        // An inner client that honours its cancellation, and otherwise waits for ever.
        delay(8.0, true, $cancellation);

        return Future::error(new LogicException('the wait ran to its end'));
    });
    $client = new RetryingIcapClient($hanging, operationTimeout: 0.2);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $hanging) {
        $baseline = $this->loopTimers();
        $calls = [
            'request'             => fn () => $client->request(new IcapRequest('REQMOD', 'icap://x/svc'))->await(),
            'options'             => fn () => $client->options('/svc')->await(),
            'scanFile'            => fn () => $client->scanFile('/svc', '/tmp/x')->await(),
            'scanFileWithPreview' => fn () => $client->scanFileWithPreview('/svc', '/tmp/x')->await(),
        ];

        $held = [];
        foreach ($calls as $name => $call) {
            $started = hrtime(true);
            $held[$name] = retryThrown($call);
            $elapsed = (hrtime(true) - $started) / 1e9;

            expect($held[$name])->toBeInstanceOf(IcapTimeoutException::class, $name)
                ->and($elapsed)->toBeGreaterThanOrEqual(0.15)->toBeLessThan(2.0);
        }

        expect($hanging->calls)->toBe(4)
            ->and($this->loopTimers())->toBe($baseline);
    });
});

it('does not return a result that came in after the end of the budget', function () {
    $late = new RetryScriptedClient(static function (): Future {
        usleep(300_000); // a client that holds the event loop, and answers when it is over

        return Future::complete(new IcapResponse(200));
    });
    $client = new RetryingIcapClient($late, operationTimeout: 0.1);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $late) {
        $thrown = retryThrown(fn () => $client->options('/svc')->await());

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($late->calls)->toBe(1);
    });
});

it('does not extend what its caller has: a budget that began earlier stays the end', function () {
    $hanging = new RetryScriptedClient(static function (int $call, ?Cancellation $cancellation): Future {
        delay(8.0, true, $cancellation);

        return Future::error(new LogicException('the wait ran to its end'));
    });
    // The inner decorator would allow a minute; the outer one, whose cancellation it gets, 0.2 seconds.
    $inner = new RetryingIcapClient($hanging, operationTimeout: 60.0);
    $outer = new RetryingIcapClient($inner, operationTimeout: 0.2);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($outer, $hanging) {
        $started = hrtime(true);
        $thrown = retryThrown(fn () => $outer->options('/svc')->await());
        $elapsed = (hrtime(true) - $started) / 1e9;

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.15)->toBeLessThan(2.0)
            ->and($hanging->calls)->toBe(1);
    });
});

it('makes no attempt when the budget is over before the call begins, whichever operation it is', function () {
    $inner = retryBusyClient();
    $client = new RetryingIcapClient($inner, operationTimeout: 0.001);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $inner) {
        $calls = [
            'options'             => fn () => $client->options('/svc'),
            'request'             => fn () => $client->request(new IcapRequest('REQMOD', 'icap://x/svc')),
            'scanFile'            => fn () => $client->scanFile('/svc', '/tmp/x'),
            'scanFileWithPreview' => fn () => $client->scanFileWithPreview('/svc', '/tmp/x'),
        ];
        foreach ($calls as $name => $call) {
            $future = $call();
            usleep(10_000); // the event loop is held up between the call and the first turn of the fiber

            expect(retryThrown(fn () => $future->await()))->toBeInstanceOf(IcapTimeoutException::class, $name);
        }

        expect($inner->calls)->toBe(0);
    });
});

it('does not call a sleeper when the attempt itself used up the budget', function () {
    $slept = 0;
    $inner = new RetryScriptedClient(static function (int $call): Future {
        usleep(200_000); // the attempt holds the event loop for longer than the whole budget

        return Future::error(new IcapServerException('busy ' . $call, 503));
    });
    $client = new RetryingIcapClient(
        $inner,
        maxAttempts: 3,
        sleeper: function (float $seconds) use (&$slept): void {
            $slept++;
        },
        operationTimeout: 0.1,
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $inner, &$slept) {
        $thrown = retryThrown(fn () => $client->options('/svc')->await());

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(IcapServerException::class)
            ->and($slept)->toBe(0)
            ->and($inner->calls)->toBe(1);
    });
});

it('takes a random draw that is out of range, or not a number, for what it can be', function () {
    $pauses = [];
    $draws = [5.0, -3.0, NAN];
    $client = new RetryingIcapClient(
        retryBusyClient(),
        maxAttempts: 4,
        baseDelaySeconds: 0.1,
        maxDelaySeconds: 0.25,
        backoffFactor: 2.0,
        sleeper: function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        },
        random: function () use (&$draws): float {
            return array_shift($draws) ?? 1.0;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(IcapServerException::class);
    });

    // A draw above 1 is all of the delay, one below 0 is none of it, and no number at all is all of it.
    expect($pauses)->toBe([0.1, 0.0, 0.25]);
});

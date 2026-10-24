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
use ArrayObject;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\OperationDeadline;
use RuntimeException;

use function Amp\delay;

uses(AsyncTestCase::class);

/*
 * M10 — the end of one operation. OperationDeadline is internal; what these tests hold it to is what the clients and
 * the transports rely on: the end is a point on the monotonic clock that nothing extends, it is looked at and not only
 * waited for, the caller's cancellation and the end of the operation are told apart (and the caller wins), and the
 * timer and the subscription are gone when the operation is closed.
 */

/** What the callbacks of a test were told. */
final class DeadlineTold
{
    public int $kept = 0;
    public int $dropped = 0;
    public int $late = 0;
    public ?\Throwable $previous = null;
}

/** A cancellation that counts what is subscribed to it and what is given back. */
final class DeadlineCountingCancellation implements Cancellation
{
    public int $active = 0;
    private int $next = 0;
    /** @var array<string, \Closure> */
    private array $callbacks = [];

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $id = 'c' . ++$this->next;
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
        return false;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
    }
}

it('never extends the end of the operation it belongs to', function () {
    $outer = OperationDeadline::begin(null, 5.0);
    // Asks for a minute, and gets what is left of the outer operation; asks for less, and gets less.
    $nested = OperationDeadline::begin($outer, 60.0);
    $shorter = OperationDeadline::begin($outer, 0.02);

    // The outer end is read first, so the same end read a moment later is the smaller.
    $left = $outer->remaining();
    expect($nested->remaining())->toBeLessThanOrEqual($left)
        ->and($shorter->remaining())->toBeLessThan($left);

    usleep(40_000);
    expect($shorter->hasEnded())->toBeTrue()
        ->and($nested->hasEnded())->toBeFalse()
        ->and($outer->hasEnded())->toBeFalse();

    // A call nested in an operation that is over begins over.
    $over = OperationDeadline::begin(null, 0.001);
    usleep(5_000);
    $late = OperationDeadline::begin($over, 60.0);
    expect($late->hasEnded())->toBeTrue()
        ->and($late->remaining())->toBe(0.0);
});

it('takes a huge but finite limit without overflowing', function () {
    /** @var AsyncTestCase $this */
    $deadline = OperationDeadline::begin(null, PHP_FLOAT_MAX);
    $baseline = $this->loopTimers();

    $deadline->subscribe(static function (): void {
    });
    expect(is_finite($deadline->remaining()))->toBeTrue()
        ->and($deadline->remaining())->toBeGreaterThan(1.0e6)
        ->and($deadline->hasEnded())->toBeFalse();

    $deadline->close();
    expect($this->loopTimers())->toBe($baseline);
});

it('tells the end of the operation from the cancellation of the caller, and the caller wins', function () {
    $caller = new DeferredCancellation();
    $deadline = OperationDeadline::begin($caller->getCancellation(), 0.03);

    // Neither: nothing to say, and a cancellation that nobody here caused is none of its business.
    $foreign = new CancelledException();
    expect($deadline->isRequested())->toBeFalse()
        ->and($deadline->outcome($foreign))->toBe($foreign);
    $deadline->checkpoint();

    // The end has passed: a timeout, as a CancelledException for code that knows no better, as an IcapTimeoutException
    // where the client looks.
    usleep(50_000);
    $thrown = static function (\Closure $call): ?\Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    };
    $cancelled = $thrown(fn () => $deadline->throwIfRequested());
    expect($deadline->isRequested())->toBeTrue()
        ->and($cancelled)->toBeInstanceOf(CancelledException::class)
        ->and($cancelled?->getPrevious())->toBeInstanceOf(IcapTimeoutException::class)
        ->and($thrown(fn () => $deadline->checkpoint()))->toBeInstanceOf(IcapTimeoutException::class)
        ->and($deadline->outcome(new CancelledException()))->toBeInstanceOf(IcapTimeoutException::class);
    // What is not a cancellation stays what it is, a timeout of a phase included.
    $other = new RuntimeException('not mine');
    $phase = new IcapTimeoutException('a phase');
    expect($deadline->outcome($other))->toBe($other)
        ->and($deadline->outcome($phase))->toBe($phase);

    // The cause that was running out of time is the previous exception.
    $cause = new RuntimeException('busy');
    expect($deadline->outcome(new CancelledException(), $cause)->getPrevious())->toBe($cause);

    // The caller cancels as well: both apply, and the caller's cancellation wins everywhere.
    $caller->cancel();
    expect($thrown(fn () => $deadline->throwIfRequested()))->toBeInstanceOf(CancelledException::class)
        ->and($thrown(fn () => $deadline->throwIfRequested())?->getPrevious())->not->toBeInstanceOf(IcapTimeoutException::class)
        ->and($thrown(fn () => $deadline->checkpoint()))->toBeInstanceOf(CancelledException::class)
        ->and($deadline->outcome(new CancelledException()))->toBeInstanceOf(CancelledException::class)
        ->and($deadline->outcome(new CancelledException()))->not->toBeInstanceOf(IcapTimeoutException::class);
});

it('is looked at as well as waited for: the end is found when the event loop was held up', function () {
    $deadline = OperationDeadline::begin(null, 0.03);
    $told = new DeadlineTold();
    $deadline->subscribe(static function (CancelledException $e) use ($told): void {
        $told->kept++;
        $told->previous = $e->getPrevious();
    });

    // The timer cannot fire while the loop is held up. The clock says so anyway, and the first look also tells
    // those who wait - one who comes after the hold-up, and has not looked yet, as well.
    usleep(60_000);
    $late = new DeadlineTold();
    $deadline->subscribe(static function () use ($late): void {
        $late->kept++;
    });
    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($told, $late, $deadline) {
        delay(0.01);
        expect($told->kept)->toBe(1)
            ->and($told->previous)->toBeInstanceOf(IcapTimeoutException::class)
            ->and($late->kept)->toBe(1);
        $deadline->close();
    });
});

it('tells those who wait when the end passes, once, and not those who stopped waiting', function () {
    $deadline = OperationDeadline::begin(null, 0.05);
    $told = new DeadlineTold();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($deadline, $told) {
        $deadline->subscribe(static function () use ($told): void {
            $told->kept++;
        });
        $id = $deadline->subscribe(static function () use ($told): void {
            $told->dropped++;
        });
        $deadline->unsubscribe($id);

        delay(0.09);
        expect($told->kept)->toBe(1); // the timer ran at the end of the operation (0.05 s), not at twice that
        delay(0.03);
        // A subscription after the end is told as well.
        $deadline->subscribe(static function () use ($told): void {
            $told->late++;
        });
        delay(0.01);

        expect($told->kept)->toBe(1)
            ->and($told->dropped)->toBe(0)
            ->and($told->late)->toBe(1);
        $deadline->close();
    });
});

it('forwards the cancellation of the caller to those who wait', function () {
    $caller = new DeferredCancellation();
    $deadline = OperationDeadline::begin($caller->getCancellation(), 30.0);
    $told = new DeadlineTold();

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($deadline, $caller, $told) {
        $deadline->subscribe(static function (CancelledException $e) use ($told): void {
            $told->kept++;
            $told->previous = $e->getPrevious();
        });

        $caller->cancel();
        delay(0.01);

        expect($told->kept)->toBe(1)
            // The caller's cancellation, not a timeout.
            ->and($told->previous)->not->toBeInstanceOf(IcapTimeoutException::class);
        $deadline->close();
    });
});

it('removes its timer and its subscription to the caller on close(), with the exception still held', function () {
    /** @var AsyncTestCase $this */
    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];

    try {
        $baseline = $this->loopTimers();
        $caller = new DeadlineCountingCancellation();
        $deadline = OperationDeadline::begin($caller, 0.02);
        $noop = static function (): void {
        };
        $deadline->subscribe($noop);
        $deadline->subscribe($noop); // one timer and one subscription to the caller, however many wait

        expect($this->loopTimers())->toHaveCount(count($baseline) + 1)
            ->and($caller->active)->toBe(1);

        usleep(30_000);
        try {
            $deadline->checkpoint();
        } catch (IcapTimeoutException $e) {
            $held[] = $e;
        }
        $deadline->close();
        $deadline->close(); // twice is once
        $deadline->subscribe($noop); // an operation that is over takes no new timer

        expect($held)->toHaveCount(1)
            ->and($this->loopTimers())->toBe($baseline)
            ->and($caller->active)->toBe(0);

        // Closed without ever being armed: nothing to remove, nothing set.
        $unarmed = OperationDeadline::begin($caller, 1.0);
        $unarmed->close();
        $unarmed->subscribe($noop);
        expect($this->loopTimers())->toBe($baseline)
            ->and($caller->active)->toBe(0);
    } finally {
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
});

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

namespace Ndrstmr\Icap;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Closure;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Revolt\EventLoop;

/**
 * The end of one operation (M10): a point on the monotonic clock, fixed when a public method is called, that every
 * phase of the operation, every attempt and every pause stays inside. It travels as the {@see Cancellation} of the
 * call, so that code that knows nothing about it (a connector, a producer, another client) ends with the caller's
 * cancellation and with the end of the operation alike, and code that does can ask how much time is left.
 *
 * Whoever starts an operation with {@see begin()} hands the caller's own cancellation in. If that already is an
 * operation, the new end is the earlier of the two: a nested call, an inner client, a call of the scan helper to
 * {@see IcapClient::request()}, never extends what its caller has. Separate and parallel calls begin their own.
 *
 * The end is looked at, not only waited for. A timer cannot fire while something holds the event loop (a producer, a
 * synchronous transport, a cache), so {@see throwIfRequested()}, {@see isRequested()} and {@see checkpoint()} compare
 * the clock, and the places that get control back call them; a result that comes in after the end is not a result.
 *
 * Two outcomes are told apart. A cancellation by the caller (also a `TimeoutCancellation` of theirs) stays what it is,
 * an `Amp\CancelledException`, and wins when both apply. The end of the operation is an
 * {@see IcapTimeoutException}: {@see checkpoint()} throws it, {@see outcome()} turns the `CancelledException` that
 * the end caused into it. The timer and the subscription to the caller are set by the first one who waits for the end
 * ({@see subscribe()}) and removed by {@see close()}, in a `finally`, not left to destructors: an exception that is
 * still referenced keeps the arguments of its stack frames alive.
 *
 * @internal
 */
final class OperationDeadline implements Cancellation
{
    /** More than any operation takes; it keeps the arithmetic of a huge but finite limit finite. */
    private const float LONGEST_SECONDS = 1.0e9;

    private ?DeferredCancellation $source = null;

    private ?string $timer = null;

    private ?string $callerWatch = null;

    private bool $closed = false;

    private function __construct(
        private readonly ?Cancellation $caller,
        private readonly float $endsAt,
    ) {
    }

    /**
     * An operation of at most $seconds from now, or until the end of the operation $caller belongs to if that is earlier.
     */
    public static function begin(?Cancellation $caller, float $seconds): self
    {
        $endsAt = hrtime(true) + min($seconds, self::LONGEST_SECONDS) * 1e9;

        return new self($caller, $caller instanceof self ? min($endsAt, $caller->endsAt) : $endsAt);
    }

    /**
     * Seconds left; 0 once the end has passed.
     */
    public function remaining(): float
    {
        return max(0.0, ($this->endsAt - hrtime(true)) / 1e9);
    }

    /**
     * Whether the end has passed. This is the clock: the timer that tells those who wait runs when the event loop
     * does.
     */
    public function hasEnded(): bool
    {
        return hrtime(true) >= $this->endsAt;
    }

    /**
     * Throws what the caller's own cancellation throws, if it has been requested - not the end of the operation,
     * also not that of an operation the caller belongs to, which is a timeout of this one as well.
     *
     * @throws CancelledException
     */
    public function throwIfCallerRequested(): void
    {
        if ($this->caller instanceof self) {
            $this->caller->throwIfCallerRequested();

            return;
        }

        $this->caller?->throwIfRequested();
    }

    /**
     * For the places that get control back from something that does not look by itself: the caller's cancellation
     * as the exception it is, the end of the operation as an {@see IcapTimeoutException}.
     *
     * @param \Throwable|null $cause what the operation was doing when it ran out (kept as the previous exception)
     *
     * @throws CancelledException   When the caller has cancelled
     * @throws IcapTimeoutException When the end of the operation has passed
     */
    public function checkpoint(?\Throwable $cause = null): void
    {
        $this->throwIfCallerRequested();

        if ($this->hasEnded()) {
            throw self::timeout($cause);
        }
    }

    /**
     * What an exception that left the operation means for the caller, as the exception to throw: the caller's
     * cancellation stays what it is, the `CancelledException` that the end of the operation caused becomes an
     * {@see IcapTimeoutException}, and everything else, a cancellation nobody here caused included, is returned as
     * it is.
     *
     * @param \Throwable|null $cause what the operation was doing when it ran out (kept as the previous exception)
     */
    public function outcome(\Throwable $e, ?\Throwable $cause = null): \Throwable
    {
        if (!$e instanceof CancelledException) {
            return $e;
        }

        try {
            $this->throwIfCallerRequested();
        } catch (CancelledException $callerCancelled) {
            return $callerCancelled;
        }

        return $this->hasEnded() ? self::timeout($cause) : $e;
    }

    /**
     * Sets the timer and the subscription to the caller, once, for whoever waits for the end; after {@see close()}
     * no more. Without a waiter there is nothing to wake, and the clock alone says whether the end has passed.
     */
    private function arm(): void
    {
        if ($this->source !== null || $this->closed) {
            return;
        }

        $source = new DeferredCancellation();
        $this->source = $source;

        $this->timer = EventLoop::delay($this->remaining(), static function () use ($source): void {
            $source->cancel(self::timeout());
        });
        $this->callerWatch = $this->caller?->subscribe(static function (CancelledException $e) use ($source): void {
            $source->cancel($e->getPrevious());
        });
    }

    /**
     * Removes the timer and the subscription. The operation is over; the object stays usable as a plain check.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        if ($this->caller !== null && $this->callerWatch !== null) {
            $this->caller->unsubscribe($this->callerWatch);
            $this->callerWatch = null;
        }
    }

    #[\Override]
    public function subscribe(Closure $callback): string
    {
        $this->arm();
        // An operation that is closed and was never armed has nothing left that could fire.
        $this->source ??= new DeferredCancellation();

        return $this->source->getCancellation()->subscribe($callback);
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        $this->source?->getCancellation()->unsubscribe($id);
    }

    #[\Override]
    public function isRequested(): bool
    {
        return ($this->caller?->isRequested() ?? false) || $this->hasEnded();
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        $this->throwIfCallerRequested();

        if ($this->hasEnded()) {
            throw new CancelledException(self::timeout());
        }
    }

    /**
     * The exception for an operation whose time is up.
     */
    public static function timeout(?\Throwable $cause = null): IcapTimeoutException
    {
        return new IcapTimeoutException('The operation did not finish within its operation timeout.', 0, $cause);
    }
}

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

namespace Ndrstmr\Icap\Transport;

use Amp\DeferredFuture;
use Amp\Socket\Socket;

/**
 * A call to {@see AmpConnectionPool::acquire()} that waits for room. The pool hands it what it was waiting for
 * ({@see $grant}: an idle socket, already marked as in use, or the room to connect) and wakes it; the call
 * itself also wakes when its wait runs out, when its caller cancels and when the pool is closed, and then finds
 * out which of the four it was.
 *
 * The wait ends at {@see $deadline}, a point on the pool's monotonic clock, whether or not the timer that wakes
 * the call has run: an event loop that something held up lets a deadline pass unnoticed, and what is granted
 * after it is not for this call.
 *
 * @internal
 */
final class PoolWaiter
{
    public Socket|PoolSlot|null $grant = null;

    /** @var DeferredFuture<null> */
    private DeferredFuture $woken;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $bucket,
        public readonly float $deadline,
    ) {
        $this->woken = new DeferredFuture();
    }

    /**
     * Wakes the call, once: a second reason to wake it changes nothing.
     */
    public function wake(): void
    {
        if (!$this->woken->isComplete()) {
            $this->woken->complete();
        }
    }

    public function await(): void
    {
        $this->woken->getFuture()->await();
    }
}

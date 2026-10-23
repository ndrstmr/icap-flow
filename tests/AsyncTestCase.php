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

namespace Ndrstmr\Icap\Tests;

use Amp\Cancellation;
use Amp\Future;
use Amp\Socket;
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;

use function Amp\async;

abstract class AsyncTestCase extends TestCase
{
    /** @var list<SocketInterface> */
    private array $peers = [];

    public function runAsyncTest(\Closure $test): void
    {
        $finished = false;
        EventLoop::queue(function () use ($test, &$finished): void {
            $test();
            $finished = true;
        });
        EventLoop::run();

        // A test that waits for something that never comes ends when the loop runs out of work, and
        // would pass without having checked a thing.
        $this->assertTrue($finished, 'The test did not run to its end: the event loop had no more work while it was suspended.');
    }

    /**
     * A real socket pair whose peer stays open until the test is over. A socket whose peer was
     * dropped is at EOF, and a pool does not hand such a socket out again.
     *
     * @return array{SocketInterface, SocketInterface} the peer and the client end
     */
    public function socketPair(): array
    {
        [$peer, $end] = Socket\createSocketPair();
        $this->peers[] = $peer;

        return [$peer, $end];
    }

    /**
     * The client end of a socket pair whose peer stays open (see {@see socketPair()}).
     */
    public function liveSocket(): SocketInterface
    {
        return $this->socketPair()[1];
    }

    /**
     * An acquire() that runs on its own, for the tests that start several at once or let one wait.
     *
     * @return Future<SocketInterface>
     */
    public function acquireLater(AmpConnectionPool $pool, Config $config, ?Cancellation $cancellation = null): Future
    {
        /** @var Future<SocketInterface> $call */
        $call = async(static fn (): SocketInterface => $pool->acquire($config, $cancellation));

        return $call;
    }

    /**
     * The ids of the timers (delays and repeats) the event loop holds. Sockets bring watchers of their own,
     * so only these count when a test looks for a timer that was not removed.
     *
     * @return list<string>
     */
    public function loopTimers(): array
    {
        return array_values(array_filter(
            EventLoop::getIdentifiers(),
            static fn (string $id): bool => in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true),
        ));
    }

    /**
     * The number of entries in a private array (or WeakMap) property of an object under test: what a registry
     * still holds after a cleanup.
     */
    public function privateCount(object $owner, string $property): int
    {
        $value = (new \ReflectionProperty($owner, $property))->getValue($owner);

        return is_array($value) || $value instanceof \Countable ? count($value) : -1;
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            $peer->close();
        }
        $this->peers = [];

        parent::tearDown();
    }
}

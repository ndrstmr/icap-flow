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

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\Socket as SocketInterface;
use Closure;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\HeaderFields;
use Revolt\EventLoop;
use WeakMap;
use WeakReference;

/**
 * In-process keep-alive pool of amphp sockets, with a limit on connections.
 *
 * **Limits (M04).** `maxConnectionsPerHost` is the most connections the pool has to one endpoint (host and port,
 * whatever the TLS context), and `maxConnections` the most it has at all (default: the same number). Connections
 * being set up, in use and idle all count, and room is reserved before the connector is asked. A call that finds
 * no room fails with {@see IcapConnectionException} unless a queue is configured: `maxWaiters` calls may then wait,
 * first come first served, each for at most `waitTimeout` seconds ({@see IcapTimeoutException}); the caller's
 * cancellation ends a wait as `Amp\CancelledException`. A wait ends at its deadline on the monotonic clock, also
 * when something held up the event loop and the timer has not run: a call whose wait is over is served nothing.
 * Only an idle socket gives way for a new connection, never one in use. Without a pool (or with
 * {@see NullConnectionPool}) the application limits its own concurrency.
 *
 * **Ownership (M06).** The pool takes back only sockets it lent out, and for the bucket they were lent for: a socket
 * it never lent out, and one given back for another endpoint or TLS context, is closed, never pooled. A socket that
 * is given back again while it is idle stays in the pool once and is handed out once; the second release changes
 * nothing. What the pool cannot tell is a second release of a socket that has been handed out again meanwhile from
 * the release of its new holder (the interface carries no lease): a session guards itself against giving back
 * twice. A socket the holder closed itself, or that was collected, gives its room back, and so does one whose
 * peer hung up and which its holder read to the end of the stream: it is closed whole. After {@see close()} idle
 * sockets are closed, calls that wait fail, a connection that is still being set up is closed when it arrives, and
 * sockets in use stay with their holders and are closed when they are given back.
 *
 * **Idle sockets (M05).** Idle sockets are a LIFO stack per bucket (host, port and TLS context; most recently used
 * first). Each records when it became idle on a monotonic clock. Every acquire ages out all of them, in every
 * bucket, and drops empty buckets; a timer (unreferenced, running only while something is idle, gone with
 * {@see close()} and with the pool) does so when nothing is acquired. The pool does not watch idle
 * sockets: when it hands one out it reads it once, and a socket whose peer has closed, that has received bytes
 * nobody asked for, or that fails, is closed instead of handed out. That is no guarantee that the peer still
 * listens; a request on a socket that died a moment later fails like any other.
 *
 * Security domains are separate pools; a rotated TLS context is a new context (or a new pool, with the old one
 * closed). Certificate files that change under a context in use change nothing for the sockets already open.
 */
final class AmpConnectionPool implements ConnectionPoolInterface
{
    /** The idle timer runs at half the idle time, but not more often than this and not less often than that. */
    private const float MIN_SWEEP_INTERVAL = 0.05;
    private const float MAX_SWEEP_INTERVAL = 10.0;

    /** The most endpoints whose Max-Connections the pool keeps; a pool serves a handful of servers, not an open set. */
    private const int MAX_ENDPOINT_LIMITS = 256;

    /** @var array<string, list<array{socket: SocketInterface, idleSince: float}>> idle sockets per bucket, oldest first */
    private array $idle = [];

    /** @var WeakMap<SocketInterface, PoolSlot> every open socket the pool lent out or holds idle */
    private WeakMap $slots;

    /** @var array<int, PoolSlot> connections being set up: room is reserved, no socket yet */
    private array $connecting = [];

    /** @var array<int, PoolWaiter> calls that wait for room, oldest first */
    private array $waiters = [];

    private int $lastWaiter = 0;

    private bool $closed = false;

    private ?string $sweeper = null;

    /** @var WeakMap<ClientTlsContext, int> */
    private WeakMap $tlsTokens;

    private int $lastTlsToken = 0;

    private int $maxConnections;

    private float $waitTimeout;

    private float $sweepInterval;

    /** @var array<string, int> Max-Connections per endpoint, as advertised */
    private array $serverMaxByEndpoint = [];

    /** @var Closure(Config, ?Cancellation): SocketInterface */
    private Closure $connector;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param int                                                    $maxConnectionsPerHost cap on the connections (being set up, in use and idle) to one endpoint, host and port, whatever the TLS context
     * @param (Closure(Config, ?Cancellation): SocketInterface)|null $connector             optional override — production code uses the amphp connector; tests can inject pre-built socket pairs
     * @param int|null                                               $serverMaxConnections  Max-Connections (RFC 3507 §4.10.2) taken as the limit of every endpoint of the pool; it can only lower `maxConnectionsPerHost`. Per endpoint: {@see tuneFromOptions()}
     * @param float                                                  $maxIdleSeconds        idle sockets older than this are closed (default 30 s)
     * @param (Closure(): float)|null                                $clock                 clock for idle-age and wait-deadline checks; defaults to a monotonic one (`hrtime`); injectable for deterministic tests
     * @param int|null                                               $maxConnections        cap on the connections of the whole pool, all endpoints together; default `maxConnectionsPerHost`
     * @param int                                                    $maxWaiters            how many calls may wait for a connection when there is none; 0 (default) is no queue: a call that finds the pool full fails at once
     * @param float|null                                             $waitTimeout           the longest a call waits, in seconds; required with a queue
     */
    public function __construct(
        private int $maxConnectionsPerHost = 8,
        ?Closure $connector = null,
        private ?int $serverMaxConnections = null,
        private float $maxIdleSeconds = 30.0,
        ?Closure $clock = null,
        ?int $maxConnections = null,
        private int $maxWaiters = 0,
        ?float $waitTimeout = null,
    ) {
        if ($maxConnectionsPerHost < 1) {
            throw new \InvalidArgumentException(
                'maxConnectionsPerHost must be >= 1, got: ' . $maxConnectionsPerHost,
            );
        }

        if ($serverMaxConnections !== null && $serverMaxConnections < 1) {
            throw new \InvalidArgumentException(
                'serverMaxConnections must be >= 1, got: ' . $serverMaxConnections,
            );
        }

        if (!is_finite($maxIdleSeconds) || $maxIdleSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'maxIdleSeconds must be a finite number > 0, got: ' . var_export($maxIdleSeconds, true),
            );
        }

        if ($maxConnections !== null && $maxConnections < 1) {
            throw new \InvalidArgumentException(
                'maxConnections must be >= 1, got: ' . $maxConnections,
            );
        }

        if ($maxWaiters < 0) {
            throw new \InvalidArgumentException(
                'maxWaiters must be >= 0, got: ' . $maxWaiters,
            );
        }

        if (($waitTimeout !== null && (!is_finite($waitTimeout) || $waitTimeout <= 0.0)) || ($maxWaiters > 0 && $waitTimeout === null)) {
            throw new \InvalidArgumentException(
                'waitTimeout must be a finite number > 0, and is required with maxWaiters > 0, got: ' . var_export($waitTimeout, true),
            );
        }

        $this->maxConnections = $maxConnections ?? $maxConnectionsPerHost;
        $this->waitTimeout = $waitTimeout ?? 0.0;
        $this->sweepInterval = max(self::MIN_SWEEP_INTERVAL, min($maxIdleSeconds / 2, self::MAX_SWEEP_INTERVAL));
        $this->connector = $connector ?? SocketOpener::open(...);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->slots = new WeakMap();
        $this->tlsTokens = new WeakMap();
    }

    public function __destruct()
    {
        $this->stopSweeper();
    }

    /**
     * @throws IcapConnectionException When the pool is closed, no connection can be offered (the limit is reached
     *         and there is no queue, or the queue is full), or the default connector fails; a connector of your own
     *         passes what it throws on unchanged
     * @throws IcapTimeoutException    When a call waited for the whole `waitTimeout` without being served
     * @throws CancelledException      When the caller's cancellation ended the call
     */
    #[\Override]
    public function acquire(Config $config, ?Cancellation $cancellation = null): SocketInterface
    {
        $this->assertOpen();
        // Cancelled before it began: neither the idle sockets nor the connector are asked.
        $cancellation?->throwIfRequested();

        $endpoint = $this->endpointOf($config);
        $bucket = $this->bucketOf($config, $endpoint);

        while (true) {
            // Calls that wait come first: a call that arrives now does not pass them.
            $grant = $this->waiters === [] ? $this->admit($endpoint, $bucket) : null;
            $grant ??= $this->wait($endpoint, $bucket, $cancellation);

            if ($grant instanceof SocketInterface) {
                $grant = $this->vet($grant, $cancellation);
                if ($grant === null) {
                    // The room it held fell under a cap meanwhile: ask again, as any call would.
                    continue;
                }
            }

            return $grant instanceof PoolSlot ? $this->connect($config, $cancellation, $grant) : $grant;
        }
    }

    #[\Override]
    public function release(Config $config, SocketInterface $socket): void
    {
        $slot = $this->slotOf($socket);

        if ($slot === null) {
            // Not a socket the pool is lending out: one it never issued, or one that is closed and forgotten.
            $socket->close();
            $this->dispatch();

            return;
        }

        if ($slot->idle) {
            // Given back before: it is in the pool once, and stays there once.
            return;
        }

        if ($this->closed || $socket->isClosed() || $slot->bucket !== $this->bucketOf($config, $this->endpointOf($config))) {
            // The pool is closed, or the socket is no use, or it is given back for another endpoint or TLS context
            // than it was lent for: another bucket would be served a connection it must not have.
            $socket->close();
            $this->dispatch();

            return;
        }

        [, $perEndpoint] = $this->occupancy();
        if (($perEndpoint[$slot->endpoint] ?? 0) > $this->endpointCap($slot->endpoint)) {
            // A cap fell below what is in use: what comes back beyond it is closed.
            $socket->close();
            $this->dispatch();

            return;
        }

        $slot->idle = true;
        $this->idle[$slot->bucket][] = ['socket' => $socket, 'idleSince' => ($this->clock)()];
        $this->startSweeper();
        $this->dispatch();
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        $this->stopSweeper();

        foreach ($this->idle as $entries) {
            foreach ($entries as $entry) {
                $entry['socket']->close();
            }
        }
        $this->idle = [];

        // Calls that wait find the pool closed. Sockets in use stay with their holders.
        foreach ($this->waiters as $waiter) {
            $waiter->wake();
        }
        $this->serverMaxByEndpoint = [];
        $this->occupancy();
    }

    /**
     * Take the `Max-Connections` header of an OPTIONS response as a limit (RFC 3507 §4.10.2). It can only lower
     * what the pool allows itself: the effective cap is the smaller of `maxConnectionsPerHost` and the number the
     * server advertised. Idle sockets above the new cap are closed at once; sockets in use run to their end, and
     * what comes back beyond the cap is closed.
     *
     * The pool does not know which service an OPTIONS response belongs to, only the endpoint, and only if told:
     * with `$config` the number applies to that endpoint (host and port). Without it the pool cannot tell whose
     * limit it is and applies it conservatively to every endpoint it serves. The pool keeps the number of at most
     * 256 endpoints; for a further one it refuses, rather than forget a limit it was told, which would be to
     * bypass it. A cap that rose serves the calls that wait.
     *
     * Typical usage after an OPTIONS round-trip:
     *
     *     $response = $client->options('/avscan')->await();
     *     $pool->tuneFromOptions($response, $config);
     *
     * @see IcapResponse
     *
     * @throws IcapMalformedResponseException When the response carries conflicting `Max-Connections` fields, or a
     *         value that is not a positive decimal number of at most nine digits
     * @throws IcapConnectionException         When the pool is closed, or already keeps the limits of as many
     *         endpoints as it takes and `$config` names another one
     */
    public function tuneFromOptions(IcapResponse $response, ?Config $config = null): void
    {
        $value = HeaderFields::single($response->headers, 'Max-Connections');
        if ($value === null) {
            return;
        }
        $advertised = self::advertised($value);
        $this->assertOpen();

        if ($config === null) {
            $this->serverMaxConnections = $advertised;
        } else {
            $endpoint = $this->endpointOf($config);
            if (!isset($this->serverMaxByEndpoint[$endpoint]) && count($this->serverMaxByEndpoint) >= self::MAX_ENDPOINT_LIMITS) {
                throw new IcapConnectionException(sprintf(
                    'The pool already keeps the Max-Connections of %d endpoints and takes no more: use another pool for other servers.',
                    self::MAX_ENDPOINT_LIMITS,
                ));
            }
            $this->serverMaxByEndpoint[$endpoint] = $advertised;
        }

        // Idle sockets above a cap that fell are closed now; sockets in use keep running and are closed on return.
        [, $perEndpoint] = $this->occupancy();
        foreach ($perEndpoint as $endpoint => $count) {
            while ($count > $this->endpointCap($endpoint) && $this->evictOldestIdle($endpoint)) {
                $count--;
            }
        }

        // A cap that rose is room for the calls that wait.
        $this->dispatch();
    }

    /**
     * A positive decimal number of at most nine digits, checked before it is cast: (int) reads '2.5' as 2,
     * '2abc' as 2 and '1e3' as 1000, and a longer number is no connection limit anyone means.
     *
     * @throws IcapMalformedResponseException
     */
    private static function advertised(string $value): int
    {
        if (preg_match('/^[0-9]{1,9}\z/', $value) !== 1 || (int) $value < 1) {
            // What the server sent stays out of the message: it would end up in logs and in handlers.
            throw new IcapMalformedResponseException('Invalid Max-Connections header in the ICAP response: not a positive decimal number of at most nine digits.');
        }

        return (int) $value;
    }

    /**
     * One atomic step of admission, without a suspension: an idle socket of the bucket, the most recently used
     * first, or reserved room for a new connection, or nothing.
     */
    private function admit(string $endpoint, string $bucket): SocketInterface|PoolSlot|null
    {
        $this->sweep();

        $entry = isset($this->idle[$bucket]) ? array_pop($this->idle[$bucket]) : null;
        if ($entry !== null) {
            if ($this->idle[$bucket] === []) {
                unset($this->idle[$bucket]);
            }
            $slot = $this->slotOf($entry['socket']);
            if ($slot !== null) {
                $slot->idle = false;
            }

            return $entry['socket'];
        }

        return $this->makeRoom($endpoint) ? $this->reserve($endpoint, $bucket) : null;
    }

    /**
     * Room for one connection, counted from now until the connection is a socket or has failed.
     */
    private function reserve(string $endpoint, string $bucket): PoolSlot
    {
        $slot = new PoolSlot($endpoint, $bucket);
        $this->connecting[spl_object_id($slot)] = $slot;

        return $slot;
    }

    /**
     * Whether a new connection to the endpoint fits, closing idle sockets (the oldest first) if that is what
     * stands in the way. A socket in use is never closed to make room: its scan is still running.
     */
    private function makeRoom(string $endpoint): bool
    {
        [$total, $perEndpoint] = $this->occupancy();
        $here = $perEndpoint[$endpoint] ?? 0;

        while ($here >= $this->endpointCap($endpoint)) {
            if (!$this->evictOldestIdle($endpoint)) {
                return false;
            }
            $here--;
            $total--;
        }

        while ($total >= $this->maxConnections) {
            if (!$this->evictOldestIdle(null)) {
                return false;
            }
            $total--;
        }

        return true;
    }

    /**
     * Waits, first come first served, for room or an idle socket. Returns what the pool granted.
     *
     * @throws IcapConnectionException No queue, or the queue is full, or the pool was closed
     * @throws IcapTimeoutException    The wait ran out
     * @throws CancelledException      The caller gave up
     */
    private function wait(string $endpoint, string $bucket, ?Cancellation $cancellation): SocketInterface|PoolSlot
    {
        if ($this->maxWaiters === 0) {
            throw new IcapConnectionException('No connection is available: the limit of the pool is reached, and the pool has no queue.');
        }
        if (count($this->waiters) >= $this->maxWaiters) {
            throw new IcapConnectionException(sprintf(
                'No connection is available: the limit of the pool is reached, and its queue of %d calls is full.',
                $this->maxWaiters,
            ));
        }

        $waiter = new PoolWaiter($endpoint, $bucket, ($this->clock)() + $this->waitTimeout);
        $id = ++$this->lastWaiter;
        $this->waiters[$id] = $waiter;
        // Timer and subscription are removed on every way out, not left to destructors: an exception that is
        // still referenced keeps the arguments of its stack frames alive.
        $timer = EventLoop::delay($this->waitTimeout, static function () use ($waiter): void {
            $waiter->wake();
        });
        $watch = $cancellation?->subscribe(static function () use ($waiter): void {
            $waiter->wake();
        });

        try {
            // This call may be servable although the calls before it are not.
            $this->dispatch();
            $waiter->await();
        } finally {
            EventLoop::cancel($timer);
            if ($cancellation !== null && $watch !== null) {
                $cancellation->unsubscribe($watch);
            }
            unset($this->waiters[$id]);
        }

        $grant = $waiter->grant;

        try {
            // The caller's cancellation wins over whatever else ended the wait.
            $cancellation?->throwIfRequested();
            $this->assertOpen();
            if ($grant === null || ($this->clock)() >= $waiter->deadline) {
                // Also what was granted after the deadline, or before it but taken over only after it, because
                // the event loop was held up: the wait is over, and the call gets nothing.
                throw new IcapTimeoutException(sprintf(
                    'No connection became available within the pool wait timeout of %s seconds.',
                    $this->waitTimeout,
                ));
            }
        } catch (\Throwable $e) {
            // Granted in the same moment the wait ended: the room goes back, once.
            if ($grant !== null) {
                $this->giveBack($grant);
            }

            throw $e;
        }

        return $grant;
    }

    /**
     * Hands room to the calls that wait, oldest first, as far as it goes. Called wherever room may have come free.
     */
    private function dispatch(): void
    {
        if ($this->waiters === []) {
            return;
        }

        $now = ($this->clock)();
        foreach ($this->waiters as $id => $waiter) {
            if ($now >= $waiter->deadline) {
                // Its wait is over, whether or not its timer has run yet: it finds that out when it wakes.
                continue;
            }

            $grant = $this->admit($waiter->endpoint, $waiter->bucket);
            if ($grant === null) {
                continue;
            }
            $waiter->grant = $grant;
            unset($this->waiters[$id]);
            $waiter->wake();
        }
    }

    /**
     * Takes back what a call was granted and will not use: the room, or the idle socket (which is closed).
     */
    private function giveBack(SocketInterface|PoolSlot $grant): void
    {
        if ($grant instanceof PoolSlot) {
            unset($this->connecting[spl_object_id($grant)]);
        } else {
            $grant->close();
        }

        $this->dispatch();
    }

    /**
     * Opens the connection the reserved room is for. The room goes back on every way out that is not a socket.
     */
    private function connect(Config $config, ?Cancellation $cancellation, PoolSlot $slot): SocketInterface
    {
        $socket = null;

        try {
            $socket = ($this->connector)($config, $cancellation);

            // A connector need not honour the token it was given, and the pool may have been closed while it
            // worked: what it hands back after that is not handed out.
            $cancellation?->throwIfRequested();
            $this->assertOpen();

            if ($this->slotOf($socket) !== null && !$socket->isClosed()) {
                // A socket the pool has lent out already is its holder's; leave it alone.
                $socket = null;
                throw new IcapConnectionException('The connector returned a socket that is in use.');
            }

            unset($this->connecting[spl_object_id($slot)]);
            $this->slots[$socket] = $slot;
            $this->watch($socket);

            return $socket;
        } catch (\Throwable $e) {
            $socket?->close();
            unset($this->connecting[spl_object_id($slot)]);
            $this->dispatch();

            throw $e;
        }
    }

    /**
     * Checks an idle socket before it is handed out: it is read once, and any byte or the end of the stream
     * means it may not be. A socket that is not fit is closed, and its room is used for a new connection, so
     * the call that was served does not lose its turn (unless a cap fell below the room it held).
     *
     * @return SocketInterface|PoolSlot|null the socket; or the room for a new connection; or null
     *
     * @throws IcapConnectionException|CancelledException When the pool was closed or the caller gave up meanwhile
     */
    private function vet(SocketInterface $socket, ?Cancellation $cancellation): SocketInterface|PoolSlot|null
    {
        try {
            $quiet = $this->isQuiet($socket);
            $cancellation?->throwIfRequested();
            $this->assertOpen();
        } catch (\Throwable $e) {
            $socket->close();
            $this->dispatch();

            throw $e;
        }

        if ($quiet) {
            return $socket;
        }

        $slot = $this->slotOf($socket);
        $socket->close();

        // The connection that is gone left room for exactly one in its place, unless a cap fell below it meanwhile.
        if ($slot === null || !$this->makeRoom($slot->endpoint)) {
            $this->dispatch();

            return null;
        }

        return $this->reserve($slot->endpoint, $slot->bucket);
    }

    /**
     * Nothing may be waiting on an idle connection: a byte is data nobody asked for, the end of the stream is
     * the peer going away, an error is an error. The read carries a cancellation that is already requested, so
     * it takes what is there and ends in the same turn of the event loop; nothing is left watching the socket,
     * and what the next user is sent is the next user's. A closed socket answers with the end of the stream. The
     * socket is closed when it is not quiet, so no byte that is taken here is missed.
     */
    private function isQuiet(SocketInterface $socket): bool
    {
        $probe = new DeferredCancellation();
        $probe->cancel();

        try {
            $socket->read($probe->getCancellation());

            return false;
        } catch (CancelledException) {
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Closes the idle sockets that are closed, or older than `maxIdleSeconds`, in every bucket, and drops the
     * buckets that are left empty.
     */
    private function sweep(): void
    {
        $now = ($this->clock)();

        foreach ($this->idle as $bucket => $entries) {
            $kept = [];
            foreach ($entries as $entry) {
                if ($entry['socket']->isClosed() || ($now - $entry['idleSince']) > $this->maxIdleSeconds) {
                    $entry['socket']->close();
                    continue;
                }
                $kept[] = $entry;
            }

            if ($kept === []) {
                unset($this->idle[$bucket]);
            } else {
                $this->idle[$bucket] = $kept;
            }
        }
    }

    /**
     * Closes the oldest idle socket, of the endpoint or of any endpoint, to make room.
     */
    private function evictOldestIdle(?string $endpoint): bool
    {
        $oldest = null;

        foreach ($this->idle as $bucket => $entries) {
            $entry = $entries[0] ?? null;
            if ($entry === null || ($endpoint !== null && $this->slotOf($entry['socket'])?->endpoint !== $endpoint)) {
                continue;
            }
            if ($oldest === null || $entry['idleSince'] < $oldest[1]) {
                $oldest = [$bucket, $entry['idleSince']];
            }
        }

        if ($oldest === null) {
            return false;
        }

        $entry = array_shift($this->idle[$oldest[0]]);
        if ($this->idle[$oldest[0]] === []) {
            unset($this->idle[$oldest[0]]);
        }
        if ($entry !== null) {
            $entry['socket']->close();
        }

        return true;
    }

    /**
     * The connections the pool answers for: those being set up, in use and idle, in all and per endpoint. A socket
     * that is closed is no connection, however it was closed; one that was collected is not even in the registry.
     *
     * @return array{int, array<string, int>}
     */
    private function occupancy(): array
    {
        $total = 0;
        $perEndpoint = [];

        foreach ($this->connecting as $slot) {
            $total++;
            $perEndpoint[$slot->endpoint] = ($perEndpoint[$slot->endpoint] ?? 0) + 1;
        }

        $closed = [];
        foreach ($this->slots as $socket => $slot) {
            if ($socket->isClosed()) {
                $closed[] = $socket;
                continue;
            }
            $total++;
            $perEndpoint[$slot->endpoint] = ($perEndpoint[$slot->endpoint] ?? 0) + 1;
        }
        foreach ($closed as $socket) {
            unset($this->slots[$socket]);
        }

        return [$total, $perEndpoint];
    }

    private function slotOf(SocketInterface $socket): ?PoolSlot
    {
        return $this->slots->offsetExists($socket) ? $this->slots[$socket] : null;
    }

    /**
     * A socket that its holder closes itself, or that is collected, gives its room back at once (the registry
     * does not count it); calls that wait are told when it happens.
     *
     * The callback is the socket's, and amphp calls it when the reading end closes. That happens when the peer
     * hung up and the holder read the end of the stream, and the writing end may be open still: the socket is not
     * closed, its room is not free, and when the holder closes it later nothing tells the pool again. A connection
     * nobody can read an answer from is no use for ICAP, so it is closed whole here, and its room is free now.
     * Neither the pool nor the socket is held by the callback: a collected socket closes itself.
     */
    private function watch(SocketInterface $socket): void
    {
        $pool = WeakReference::create($this);
        $watched = WeakReference::create($socket);
        $socket->onClose(static function () use ($pool, $watched): void {
            $watched->get()?->close();
            $pool->get()?->dispatch();
        });
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new IcapConnectionException('Pool is closed.');
        }
    }

    private function startSweeper(): void
    {
        if ($this->sweeper !== null || $this->closed) {
            return;
        }

        // The timer holds the pool weakly and does not keep the event loop running: a pool nobody holds any
        // more, or a loop that has nothing else to do, is not kept alive by it.
        $pool = WeakReference::create($this);
        $this->sweeper = EventLoop::repeat($this->sweepInterval, static function (string $id) use ($pool): void {
            $self = $pool->get();
            if ($self === null) {
                EventLoop::cancel($id);

                return;
            }
            $self->sweepIdle();
        });
        EventLoop::unreference($this->sweeper);
    }

    private function sweepIdle(): void
    {
        $this->sweep();
        if ($this->idle === []) {
            $this->stopSweeper();
        }
    }

    private function stopSweeper(): void
    {
        if ($this->sweeper !== null) {
            EventLoop::cancel($this->sweeper);
            $this->sweeper = null;
        }
    }

    /**
     * The cap on the connections to one endpoint: the pool's own, lowered by what servers advertised.
     */
    private function endpointCap(string $endpoint): int
    {
        return min(
            $this->maxConnectionsPerHost,
            $this->serverMaxConnections ?? PHP_INT_MAX,
            $this->serverMaxByEndpoint[$endpoint] ?? PHP_INT_MAX,
        );
    }

    /**
     * What a connection counts against: host and port, whatever the TLS context. Host names are not case sensitive.
     */
    private function endpointOf(Config $config): string
    {
        return strtolower($config->host) . ':' . $config->port;
    }

    /**
     * Where an idle socket may be given out: the endpoint and, for TLS, the context it was opened for.
     */
    private function bucketOf(Config $config, string $endpoint): string
    {
        $tls = $config->getTlsContext();

        return $tls === null ? $endpoint : $endpoint . ':tls:' . $this->tlsToken($tls);
    }

    /**
     * The pool's own identity for a TLS context: one number per context
     * object, handed out once and never again. An object hash would not do:
     * it is recycled as soon as the context is collected, and connectTls()
     * connects with a clone of a context without peer name, so nothing keeps
     * the original alive while its idle socket is. The next context to take
     * over the hash would then be served the old socket (B07).
     *
     * The counter only grows. Past PHP_INT_MAX the increment throws a
     * TypeError instead of wrapping, so an identity is never issued twice.
     */
    private function tlsToken(ClientTlsContext $tls): int
    {
        return $this->tlsTokens[$tls] ??= ++$this->lastTlsToken;
    }
}

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
use Amp\Socket\Socket;
use Ndrstmr\Icap\Config;

/**
 * Process-local cache of TCP/TLS connections to ICAP servers.
 *
 * The transport calls {@see acquire()} before sending the request
 * and {@see release()} after the response has been framed. The pool
 * decides whether to hand out an existing idle socket or open a new
 * one, and whether to keep a returned socket idle or close it.
 *
 * The default implementation is {@see AmpConnectionPool}. The
 * {@see NullConnectionPool} variant disables pooling — every
 * acquire opens a fresh connection, every release closes it.
 *
 * Implementations MUST be safe to use across concurrent
 * acquire/release cycles within a single fiber-driven event loop;
 * cross-process safety is the implementer's choice.
 */
interface ConnectionPoolInterface
{
    /**
     * Hand back a socket connected to the host described by $config.
     * Returns either a previously released, still-alive socket or a
     * freshly opened one. May suspend the current fiber while
     * connecting; honours $cancellation if supplied.
     */
    public function acquire(Config $config, ?Cancellation $cancellation = null): Socket;

    /**
     * Hand a socket back to the pool. The caller MUST NOT continue
     * using the socket after this call. Implementations may close
     * the socket if it isn't reusable (already closed, pool full,
     * server signalled `Connection: close`).
     */
    public function release(Config $config, Socket $socket): void;

    /**
     * Close every pooled idle socket. Idempotent. Sockets currently
     * in use by callers are unaffected — they will be closed when
     * released against this pool, since the pool won't accept new
     * idle entries after close().
     */
    public function close(): void;
}

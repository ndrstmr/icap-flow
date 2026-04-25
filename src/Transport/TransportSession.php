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

/**
 * A bound socket that can carry more than one write/read round-trip
 * before being released.
 *
 * The strict RFC 3507 §4.5 preview-continue flow needs this: the
 * client sends the preview, reads the server's `100 Continue`, then
 * sends the rest of the body — all on the same connection, all part
 * of one logical ICAP request. The plain
 * {@see TransportInterface::request()} method is one-shot and can't
 * model that.
 *
 * Sessions are obtained from
 * {@see SessionAwareTransport::openSession()}. Callers MUST call
 * either {@see release()} (return to the pool when configured, close
 * otherwise) or {@see close()} (force-close), exactly once.
 */
interface TransportSession
{
    /**
     * Push request bytes onto the socket. Honours the cancellation
     * token the session was opened with.
     *
     * @param iterable<string> $chunks
     */
    public function write(iterable $chunks): void;

    /**
     * Read one fully-framed ICAP response from the socket. The
     * underlying {@see ResponseFrameReader} stops as soon as the
     * message is complete, leaving any trailing bytes in the kernel
     * buffer for the next call.
     */
    public function readResponse(): string;

    /**
     * Return the socket to the underlying pool when one is configured;
     * otherwise close it. Idempotent in the sense that further calls
     * are no-ops.
     */
    public function release(): void;

    /**
     * Force-close the socket without offering it back to a pool.
     * Required when the protocol exchange went off the rails (parse
     * error, server-side `Connection: close`, exception inside the
     * request flow).
     */
    public function close(): void;
}

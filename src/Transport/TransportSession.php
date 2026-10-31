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
     * Push request bytes onto the socket, all chunks of the call within one
     * window of `streamTimeout` seconds and under the cancellation token the
     * session was opened with. A call that does not finish ends the session
     * for good (it is closed, and {@see release()} cannot pool it): a
     * cancellation by the caller, also a `TimeoutCancellation`, throws
     * `Amp\CancelledException`, the window passing throws
     * {@see \Ndrstmr\Icap\Exception\IcapTimeoutException}. Both are looked
     * at before the producer is asked for anything, whenever it hands a chunk
     * back, an empty one too, after every write and before the call returns:
     * a call that is cancelled or out of time before it begins asks the
     * producer for nothing, and a producer that is slow, or blocks the event
     * loop, cannot extend the window or finish the call after it.
     *
     * @param iterable<string> $chunks
     */
    public function write(iterable $chunks): void;

    /**
     * Read one fully-framed ICAP response from the socket. The response ends
     * where its layout says ({@see ResponseFrameReader}); one that is illegal,
     * that the stream cuts off, or behind which the read brought in further
     * bytes throws {@see \Ndrstmr\Icap\Exception\IcapMalformedResponseException}
     * and ends the session: a connection carries one outstanding request at a
     * time (RFC 3507 §4.1), so those bytes answer nothing. Bytes that arrive
     * later are not waited for. Cancellation and failure behave as for
     * {@see write()}; the windows are two, one after the other (M10): the
     * server's time to begin its answer once the request is sent
     * (`Config::getResponseStartTimeout()`), then, from the first byte, a
     * maximum for the answer to arrive in full (`Config::getStreamTimeout()`)
     * that no further byte starts again.
     */
    public function readResponse(): string;

    /**
     * Return the socket to the underlying pool when one is configured;
     * otherwise close it. Call it only after the whole exchange has been
     * validated (frame, status, verdict, no `Connection: close`): a
     * released socket is out of reach of {@see close()}. Idempotent in
     * the sense that further calls are no-ops.
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

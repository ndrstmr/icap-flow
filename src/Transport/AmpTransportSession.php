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
use Amp\Socket\Socket as SocketInterface;
use Ndrstmr\Icap\Config;

/**
 * One-acquired-socket binding used for multi-round-trip flows like
 * the strict RFC 3507 §4.5 preview-continue exchange.
 *
 * Lifecycle:
 *   - {@see AsyncAmpTransport::openSession()} acquires a socket from
 *     the pool (or opens a fresh one).
 *   - The caller writes / reads as many times as the protocol
 *     requires.
 *   - The caller calls {@see release()} on success (returns to the
 *     pool when one is configured) or {@see close()} on failure.
 */
final class AmpTransportSession implements TransportSession
{
    private bool $disposed = false;

    public function __construct(
        private readonly Config $config,
        private readonly SocketInterface $socket,
        private readonly Cancellation $cancellation,
        private readonly int $maxResponseSize,
        private readonly int $maxHeaderLineLength,
        private readonly ?ConnectionPoolInterface $pool,
    ) {
    }

    #[\Override]
    public function write(iterable $chunks): void
    {
        $this->assertActive();
        foreach ($chunks as $chunk) {
            if ($chunk !== '') {
                $this->socket->write($chunk);
            }
        }
    }

    #[\Override]
    public function readResponse(): string
    {
        $this->assertActive();
        $reader = new ResponseFrameReader(
            maxResponseSize: $this->maxResponseSize,
            maxHeaderLineLength: $this->maxHeaderLineLength,
        );
        $cancellation = $this->cancellation;
        $socket = $this->socket;
        return $reader->readFrom(static fn (): ?string => $socket->read($cancellation));
    }

    #[\Override]
    public function release(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        if ($this->pool !== null) {
            $this->pool->release($this->config, $this->socket);
            return;
        }
        $this->socket->close();
    }

    #[\Override]
    public function close(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $this->socket->close();
    }

    private function assertActive(): void
    {
        if ($this->disposed) {
            throw new \LogicException('TransportSession has already been released or closed.');
        }
    }
}

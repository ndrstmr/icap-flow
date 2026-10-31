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
use Amp\Future;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\TransportSession;

/**
 * Transport double for source tests: drains every request the way a real
 * transport writes it, keeps what went over the wire and answers from a
 * script, one reply per request. A request that breaks off while it is
 * written keeps the bytes that were written before it did.
 *
 * A session takes every `write()` as a request of its own and answers the read
 * that follows with the reply of the latest one, so a preview and the rest of
 * the file are two entries of `requests`, as they are two writes on the wire.
 */
final class ScriptedTransport implements SessionAwareTransport
{
    /** @var list<string> */
    public array $requests = [];

    public int $released = 0;

    public int $closed = 0;

    /**
     * @param list<string>                         $replies one reply per request, in order; 'fail' fails the request,
     *                                                      and a request beyond the script is answered with 204
     * @param (\Closure(int, string): void)|null   $onChunk called with the index and the bytes of every chunk
     *                                                      before it is taken, for a source that changes mid-request
     */
    public function __construct(private readonly array $replies = [], private readonly ?\Closure $onChunk = null)
    {
    }

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        $this->drain($rawRequest);
        $reply = $this->reply();

        return $reply === 'fail'
            ? Future::error(new IcapConnectionException('transport failed'))
            : Future::complete($reply);
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession
    {
        $transport = $this;

        return new class ($transport) implements TransportSession {
            public function __construct(private readonly ScriptedTransport $transport)
            {
            }

            #[\Override]
            public function write(iterable $chunks): void
            {
                $this->transport->drain($chunks);
            }

            #[\Override]
            public function readResponse(): string
            {
                $reply = $this->transport->reply();

                return $reply === 'fail' ? throw new IcapConnectionException('transport failed') : $reply;
            }

            #[\Override]
            public function release(): void
            {
                $this->transport->released++;
            }

            #[\Override]
            public function close(): void
            {
                $this->transport->closed++;
            }
        };
    }

    /**
     * What a write takes: every chunk, in order, kept as one request.
     *
     * @param iterable<string> $chunks
     */
    public function drain(iterable $chunks): void
    {
        $wire = '';
        $index = 0;
        try {
            foreach ($chunks as $chunk) {
                ($this->onChunk)?->__invoke($index++, $chunk);
                $wire .= $chunk;
            }
        } finally {
            $this->requests[] = $wire;
        }
    }

    /**
     * The reply to the latest request.
     */
    public function reply(): string
    {
        return $this->replies[count($this->requests) - 1]
            ?? "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    }
}

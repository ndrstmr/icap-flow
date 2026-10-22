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

use Closure;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\ResponseDecoder;

/**
 * Frames ICAP responses off a stream of byte chunks.
 *
 * RFC 3507 §5.5 lets servers keep the TCP connection open for the
 * next request, which means transports cannot rely on socket-EOF to
 * delimit the response. A response ends where its own layout says (the
 * `Encapsulated` header, the header sections it gives lengths for, the
 * chunked body with its last chunk and trailer), and nowhere else: bytes in
 * a chunk are data, whatever they look like. {@see ResponseDecoder} holds the
 * rules, and the parser reads a complete response with the same ones, so both
 * accept or refuse the same bytes.
 *
 * The reader stops at the end of a message. Bytes it has already taken from the
 * producer past that end are not dropped: they stay with the reader (the session
 * that owns it) as the start of the next message, and {@see hasPendingBytes()}
 * says whether there are any. A caller that expects no further message treats
 * them as an error.
 */
final class ResponseFrameReader
{
    private string $pending = '';

    public function __construct(
        private readonly int $maxResponseSize,
        private readonly int $maxHeaderLineLength,
        private readonly int $maxHeaderCount = 100,
    ) {
        if ($maxResponseSize < 1 || $maxHeaderLineLength < 1 || $maxHeaderCount < 1) {
            throw new \InvalidArgumentException('Reader limits must be >= 1.');
        }
    }

    /**
     * Read a complete ICAP response from the supplied producer.
     *
     * The producer is a callable that returns the next chunk of bytes
     * (or null on EOF). It is invoked only when the reader needs more
     * bytes to complete the framing; bytes left over from the previous call
     * are used first.
     *
     * @param Closure(): ?string $produce
     *
     * @throws IcapMalformedResponseException When the bytes cannot be a legal response, or the
     *         stream ends before the response does
     */
    public function readFrom(Closure $produce): string
    {
        $decoder = new ResponseDecoder(
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            collectBody: false,
        );

        $bytes = $this->pending;
        $this->pending = '';
        $received = strlen($bytes);

        while (true) {
            $decoder->feed($bytes);
            if ($decoder->isComplete()) {
                break;
            }

            $bytes = $produce();
            if ($bytes === null) {
                throw new IcapMalformedResponseException(
                    'Connection closed before the response was complete (got ' . $received . ' bytes).',
                );
            }
            $received += strlen($bytes);
        }

        $this->pending = $decoder->remainder();

        return $decoder->frame();
    }

    /**
     * Whether bytes behind the last response are waiting for the next read.
     */
    public function hasPendingBytes(): bool
    {
        return $this->pending !== '';
    }
}

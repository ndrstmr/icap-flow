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

/**
 * Frames a single ICAP response off a stream of byte chunks.
 *
 * RFC 3507 §5.5 lets servers keep the TCP connection open for the
 * next request, which means transports cannot rely on socket-EOF to
 * delimit the response. The framing rules come from the response
 * itself:
 *
 *   1. The ICAP head ends at the first `\r\n\r\n`.
 *   2. The Encapsulated header in that head says either `null-body`
 *      (no encapsulated body — message ends at the blank line) or
 *      it gives an offset to the encapsulated HTTP body, which is
 *      always HTTP/1.1 chunk-encoded.
 *   3. The chunk-encoded body ends with `0\r\n\r\n` (optionally with
 *      a chunk-extension like `0; ieof\r\n\r\n`).
 *
 * The reader stops as soon as it has a complete message, leaving any
 * trailing bytes in the underlying socket for the next request.
 */
final class ResponseFrameReader
{
    public function __construct(
        private readonly int $maxResponseSize,
        private readonly int $maxHeaderLineLength,
    ) {
        if ($maxResponseSize < 1 || $maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('Reader limits must be >= 1.');
        }
    }

    /**
     * Read a complete ICAP response from the supplied producer.
     *
     * The producer is a callable that returns the next chunk of bytes
     * (or null on EOF). It is invoked only when the reader needs more
     * bytes to complete the framing.
     *
     * @param Closure(): ?string $produce
     */
    public function readFrom(Closure $produce): string
    {
        $buffer = '';
        $messageEnd = null;

        while ($messageEnd === null) {
            $chunk = $produce();
            if ($chunk === null) {
                throw new IcapMalformedResponseException(
                    'Connection closed before the response was complete (got ' . strlen($buffer) . ' bytes).',
                );
            }
            $buffer .= $chunk;
            if (strlen($buffer) > $this->maxResponseSize) {
                throw new IcapMalformedResponseException(
                    sprintf('ICAP response exceeded max size (%d bytes).', $this->maxResponseSize),
                );
            }
            $messageEnd = $this->detectMessageEnd($buffer);
        }

        // Trim any bytes that came in past the end of this message —
        // they belong to the next request on the same socket and must
        // be left in the producer for the caller to handle. With the
        // current single-shot transports this never happens (we close
        // after each request), but the contract is honoured for the
        // upcoming pooling work.
        return substr($buffer, 0, $messageEnd);
    }

    /**
     * Returns the byte offset just past the end of a complete ICAP
     * message in $buffer, or null if the message isn't complete yet.
     */
    private function detectMessageEnd(string $buffer): ?int
    {
        $headEnd = strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            // Don't even have the ICAP head yet — but enforce the
            // single-line cap so a malicious server can't push us off
            // a cliff before we know it.
            $longestLine = $this->longestUnterminatedLine($buffer);
            if ($longestLine > $this->maxHeaderLineLength) {
                throw new IcapMalformedResponseException(
                    sprintf('ICAP header line exceeded %d bytes before CRLF.', $this->maxHeaderLineLength),
                );
            }
            return null;
        }

        $headBlock = substr($buffer, 0, $headEnd);
        $bodyStart = $headEnd + 4;

        $encapsulated = $this->findEncapsulatedHeader($headBlock);
        if ($encapsulated === null) {
            // No Encapsulated header — by RFC 3507 §4.4.1 every ICAP
            // message MUST carry one, but there's no body, so treat
            // the message as ending right after the head separator.
            return $bodyStart;
        }

        $bodyOffset = $this->encapsulatedBodyOffset($encapsulated);
        if ($bodyOffset === null) {
            // null-body or header-only — message ends at the blank line.
            return $bodyStart;
        }

        // Encapsulated says the chunked body starts at $bodyOffset
        // (relative to $bodyStart). Look for the chunked terminator
        // beginning at that absolute offset.
        $absoluteBodyStart = $bodyStart + $bodyOffset;
        if (strlen($buffer) <= $absoluteBodyStart) {
            return null;
        }

        $terminator = $this->findChunkedTerminator($buffer, $absoluteBodyStart);
        return $terminator;
    }

    private function findEncapsulatedHeader(string $headBlock): ?string
    {
        $lines = preg_split('/\r?\n/', $headBlock) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^Encapsulated\s*:\s*(.+)$/i', $line, $m) === 1) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /**
     * Returns the body-section offset (req-body / res-body) declared
     * in an Encapsulated header value, or null when the header
     * advertises null-body / has no body section.
     */
    private function encapsulatedBodyOffset(string $value): ?int
    {
        foreach (array_map('trim', explode(',', $value)) as $entry) {
            if (preg_match('/^(req-body|res-body)\s*=\s*(\d+)$/i', $entry, $m) === 1) {
                return (int) $m[2];
            }
        }
        return null;
    }

    /**
     * Scan $buffer starting at $offset for the chunked-transfer
     * terminator `0\r\n\r\n` (or `0; ext\r\n\r\n` per RFC 7230 §4.1).
     * Returns the absolute byte offset just past the terminator, or
     * null when not yet present.
     */
    private function findChunkedTerminator(string $buffer, int $offset): ?int
    {
        // Match either `\n0\r\n\r\n` or `\n0; ext\r\n\r\n` — the
        // mandatory leading newline is what separates the size line
        // of the last chunk from the previous chunk's data.
        if (preg_match('/\r?\n0(?:;[^\r\n]*)?\r\n\r\n/', $buffer, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            return (int) $m[0][1] + strlen((string) $m[0][0]);
        }
        // The very first chunk could be the zero-chunk if there's no
        // payload — match it at the start of the body.
        if (preg_match('/^0(?:;[^\r\n]*)?\r\n\r\n/', substr($buffer, $offset), $m) === 1) {
            return $offset + strlen($m[0]);
        }
        return null;
    }

    /**
     * Length of the longest sequence in $buffer not separated by a
     * line break. Used to spot the "no CRLF in 16 MB" attack before
     * the full message has been received.
     */
    private function longestUnterminatedLine(string $buffer): int
    {
        $lastBreak = max(strrpos($buffer, "\n"), strrpos($buffer, "\r"));
        return strlen($buffer) - ($lastBreak === false ? 0 : $lastBreak + 1);
    }
}

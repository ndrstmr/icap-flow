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

namespace Ndrstmr\Icap;

/**
 * HTTP/1.1 chunked-transfer encoder used by both the {@see
 * RequestFormatter} (for the encapsulated body inside an ICAP request)
 * and by the strict RFC 3507 §4.5 preview-continue path (where the
 * client sends the rest of the body on the same socket after the
 * server's `100 Continue` response).
 *
 * Strings are emitted as a single chunk; resources are read in
 * {@see self::CHUNK_SIZE} blocks so a multi-gigabyte body never needs
 * to reside in memory.
 *
 * A stream body ends only at an end the encoder has seen. A read error, a
 * read that makes no progress before the end, and a stream that has to be
 * rewound but cannot be all raise a \RuntimeException, and the terminator
 * (with or without `ieof`) is not written: a source failure must not turn
 * into a shorter body that looks complete. The same holds for a stream that
 * does not have the length announced for it.
 */
final class ChunkedBodyEncoder
{
    public const int CHUNK_SIZE = 8192;

    /**
     * @param  string|resource  $body
     * @param  int|null         $length bytes a stream body has to deliver, or null for "until its
     *                                  end"; a string is its own length
     * @return iterable<string>
     *
     * @throws \RuntimeException When a stream body cannot be rewound to its
     *         start, cannot be read, stops without reaching its end, or does
     *         not match `$length`
     */
    public function encode(mixed $body, bool $previewIsComplete = false, ?int $length = null): iterable
    {
        $terminator = $previewIsComplete ? "0; ieof\r\n\r\n" : "0\r\n\r\n";

        if (is_string($body)) {
            if ($body !== '') {
                yield dechex(strlen($body)) . "\r\n" . $body . "\r\n";
            }
            yield $terminator;
            return;
        }

        if (!is_resource($body)) {
            throw new \InvalidArgumentException(
                'Encapsulated HTTP body must be a string or a readable stream resource.',
            );
        }

        // The whole body goes out, so the stream has to stand at its start.
        // One that was read before must go back; if it cannot, what was
        // consumed would be missing from the body. A stream still at its
        // start is not touched, so a non-seekable one works as before.
        if (ftell($body) !== 0 && !rewind($body)) {
            throw new \RuntimeException('The body stream was read before and cannot be rewound to its start.');
        }

        foreach ($this->streamChunks($body, $length) as $chunk) {
            yield $chunk;
        }
        yield $terminator;
    }

    /**
     * Encode the remainder of an already-partially-read stream as
     * HTTP/1.1 chunked-transfer. Reads from the **current** stream
     * position (after the preview bytes) without rewinding.
     *
     * Used by the strict RFC 3507 §4.5 continuation path: the client
     * has already sent the preview chunk, received `100 Continue`, and
     * now streams the rest of the body on the same socket.
     *
     * With `$length` the stream has to deliver exactly that many bytes and
     * then end: a source that ends early, or holds more, is an error and the
     * terminator is not written. The preview flow passes the size it
     * announced for the rest.
     *
     * @param  resource         $stream  readable stream positioned past the preview
     * @param  int|null         $length  bytes the stream must deliver, or null for "until its end"
     * @return iterable<string>
     *
     * @throws \RuntimeException When the stream cannot be read, stops without
     *         reaching its end, or does not match `$length`
     */
    public function encodeRemainderFromStream(mixed $stream, ?int $length = null): iterable
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(
                'Expected a readable stream resource.',
            );
        }

        foreach ($this->streamChunks($stream, $length) as $chunk) {
            yield $chunk;
        }
        yield "0\r\n\r\n";
    }

    /**
     * Chunk-encode a stream from its current position to its end, in
     * {@see self::CHUNK_SIZE} blocks. With `$length` it has to deliver exactly
     * that many bytes and then end: at most that many are read, and a source
     * that ends early, or still holds a byte after them, raises before the
     * caller can write a terminator.
     *
     * The public methods pass its chunks on with `yield`, not `yield from`: the
     * keys of delegated chunks would collide with the terminator's for a
     * caller that collects them with `iterator_to_array()`.
     *
     * @param  resource       $stream
     * @return iterable<string>
     *
     * @throws \RuntimeException When the stream cannot be read, stops without
     *         reaching its end, or does not match `$length`
     */
    private function streamChunks(mixed $stream, ?int $length): iterable
    {
        $sent = 0;
        while ($length === null || $sent < $length) {
            $want = $length === null ? self::CHUNK_SIZE : max(1, min(self::CHUNK_SIZE, $length - $sent));
            $block = $this->readBlock($stream, $want);
            if ($block === null) {
                break;
            }
            $sent += strlen($block);
            yield dechex(strlen($block)) . "\r\n" . $block . "\r\n";
        }

        if ($length === null) {
            return;
        }
        if ($sent !== $length) {
            throw new \RuntimeException(sprintf(
                'The body stream ended after %d of the %d bytes announced.',
                $sent,
                $length,
            ));
        }
        // Everything announced was read; nothing may follow it.
        if ($this->readBlock($stream, 1) !== null) {
            throw new \RuntimeException(sprintf(
                'The body stream holds more than the %d bytes announced.',
                $length,
            ));
        }
    }

    /**
     * Read the next block of a stream, or null once its end is confirmed.
     * Only a stream that reports its end ends the body: `false`, or an empty
     * read while the stream is not at its end, is a failure and not a
     * shorter body (B08).
     *
     * @param  resource    $stream
     * @param  int<1, max> $length bytes to ask for
     *
     * @throws \RuntimeException When the read fails or makes no progress
     */
    private function readBlock(mixed $stream, int $length): ?string
    {
        $block = fread($stream, $length);
        if ($block === false || ($block === '' && !feof($stream))) {
            throw new \RuntimeException('The body stream cannot be read to its end.');
        }

        return $block === '' ? null : $block;
    }
}

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

use Ndrstmr\Icap\ChunkedBodyEncoder;
use Ndrstmr\Icap\Tests\ScriptedSource;

/**
 * v2.1.2 — encodeRemainderFromStream() must read from the current
 * stream position (post-preview) without rewinding, emit proper
 * HTTP/1.1 chunked-transfer frames, and terminate with 0\r\n\r\n.
 */

/**
 * Open a php://memory stream, write $data, and seek to $position.
 *
 * @return resource
 */
function memoryStream(string $data, int $position = 0): mixed
{
    $stream = fopen('php://memory', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Failed to open php://memory');
    }
    fwrite($stream, $data);
    fseek($stream, $position);

    return $stream;
}

it('encodeRemainderFromStream reads from current position without rewinding', function () {
    $encoder = new ChunkedBodyEncoder();
    $stream = memoryStream(str_repeat('P', 32) . str_repeat('R', 64), 32);

    $chunks = iterator_to_array($encoder->encodeRemainderFromStream($stream));
    $wire = implode('', $chunks);

    // Must NOT contain the preview bytes ('P').
    expect($wire)->not->toContain('P');
    // Must contain the remainder bytes ('R').
    expect($wire)->toContain('R');
    // Must end with the zero-length terminator.
    expect($wire)->toEndWith("0\r\n\r\n");

    fclose($stream);
});

it('encodeRemainderFromStream emits valid chunked-transfer encoding', function () {
    $encoder = new ChunkedBodyEncoder();
    $stream = memoryStream(str_repeat('X', 100));

    $chunks = iterator_to_array($encoder->encodeRemainderFromStream($stream));
    $wire = implode('', $chunks);

    // Parse: first chunk should be "64\r\n" + 100 bytes + "\r\n",
    // followed by "0\r\n\r\n".
    expect($wire)->toStartWith(dechex(100) . "\r\n")
        ->and($wire)->toEndWith("0\r\n\r\n");

    fclose($stream);
});

it('encodeRemainderFromStream handles empty remainder', function () {
    $encoder = new ChunkedBodyEncoder();
    $stream = memoryStream(str_repeat('P', 32), 32);

    $chunks = iterator_to_array($encoder->encodeRemainderFromStream($stream));
    $wire = implode('', $chunks);

    // Only the terminator.
    expect($wire)->toBe("0\r\n\r\n");

    fclose($stream);
});

it('encodeRemainderFromStream emits multiple chunks for large streams', function () {
    $encoder = new ChunkedBodyEncoder();

    // 3 * CHUNK_SIZE worth of data to force multiple reads.
    $size = ChunkedBodyEncoder::CHUNK_SIZE * 3;
    $stream = memoryStream(str_repeat('D', $size));

    $chunks = iterator_to_array($encoder->encodeRemainderFromStream($stream));

    // At least 3 data chunks + 1 terminator = 4 entries.
    expect(count($chunks))->toBeGreaterThanOrEqual(4);
    // Last chunk is the terminator.
    expect(end($chunks))->toBe("0\r\n\r\n");

    // Total decoded payload must equal the original size.
    $decoded = '';
    foreach ($chunks as $chunk) {
        if ($chunk === "0\r\n\r\n") {
            break;
        }
        // Each chunk: hex-size\r\nDATA\r\n
        $parts = explode("\r\n", $chunk, 2);
        $decoded .= substr($parts[1], 0, -2); // strip trailing \r\n
    }
    expect(strlen($decoded))->toBe($size);

    fclose($stream);
});

/**
 * B08, M07 — a source that fails must not look like a body that ended. The
 * terminator, with or without `ieof`, goes out only after the end of the
 * stream was observed; an error or an empty read before that used to stop
 * the loop, and the shortened body was sent as complete. With an announced
 * length the stream holds exactly that many bytes, in the whole body as in
 * the remainder behind a preview.
 */
it('terminates a stream body only at its confirmed end', function () {
    $encoder = new ChunkedBodyEncoder();
    $data = str_repeat('D', 20000);

    $drain = static function (iterable $chunks): array {
        $wire = '';
        $error = null;
        try {
            foreach ($chunks as $chunk) {
                $wire .= $chunk;
            }
        } catch (RuntimeException $e) {
            $error = $e;
        }

        return [$wire, $error];
    };

    $cases = [
        // label => [method, source, announced length, fails, bytes read beforehand]
        'whole body, ieof after the end'      => ['encode', fn () => ScriptedSource::add('c', $data), null, false, 0],
        'read error in the middle'            => ['encode', fn () => ScriptedSource::add('c', $data, failAfter: 10000), null, true, 0],
        'no progress before the end'          => ['encode', fn () => ScriptedSource::add('c', $data, stallAfter: 10000), null, true, 0],
        'consumed stream that cannot rewind'  => ['encode', fn () => ScriptedSource::add('c', $data, seekable: false), null, true, 5],
        'whole body, announced length'        => ['encode', fn () => ScriptedSource::add('c', $data), 20000, false, 0],
        'whole body, longer than announced'   => ['encode', fn () => ScriptedSource::add('c', $data), 19900, true, 0],
        'whole body, shorter than announced'  => ['encode', fn () => ScriptedSource::add('c', $data), 20100, true, 0],
        'remainder, whole stream'             => ['remainder', fn () => ScriptedSource::add('c', $data), 20000, false, 0],
        'remainder, read error'               => ['remainder', fn () => ScriptedSource::add('c', $data, failAfter: 10000), null, true, 0],
        'remainder, no progress'              => ['remainder', fn () => ScriptedSource::add('c', $data, stallAfter: 10000), null, true, 0],
        'remainder, longer than announced'    => ['remainder', fn () => ScriptedSource::add('c', $data), 19900, true, 0],
        'remainder, shorter than announced'   => ['remainder', fn () => ScriptedSource::add('c', $data), 20100, true, 0],
    ];

    foreach ($cases as $case => [$method, $source, $length, $fails, $consumed]) {
        $stream = fopen($source(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open the scripted source.');
        }

        try {
            if ($consumed > 0) {
                fread($stream, $consumed);
            }
            [$wire, $error] = $drain($method === 'encode'
                ? $encoder->encode($stream, previewIsComplete: true, length: $length)
                : $encoder->encodeRemainderFromStream($stream, $length));

            if ($fails) {
                expect($error)->toBeInstanceOf(RuntimeException::class, $case);
                expect(str_contains($wire, "0\r\n\r\n") || str_contains($wire, 'ieof'))->toBeFalse($case);
            } else {
                expect($error)->toBeNull($case);
                expect(str_ends_with($wire, $method === 'encode' ? "0; ieof\r\n\r\n" : "0\r\n\r\n"))->toBeTrue($case);
            }
        } finally {
            fclose($stream);
            ScriptedSource::reset();
        }
    }
});

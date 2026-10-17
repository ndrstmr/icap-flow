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

use Amp\Socket;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

uses(AsyncTestCase::class);

/**
 * Strict RFC 3507 §4.5 preview-continue: when the server answers
 * `100 Continue`, the client sends ONLY the chunked body remainder
 * on the same socket — no second ICAP request. This test wires a
 * Socket pair into the connection pool, drives a fake server on the
 * far end, and asserts the client never opens a second connection.
 */

it('sends preview, reads 100, then writes only the body remainder on the same socket', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();

    // Inject our pre-paired client socket via the pool's connector.
    $connectorCalls = 0;
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use ($clientEnd, &$connectorCalls) {
            $connectorCalls++;
            return $clientEnd;
        },
    );
    $config = new Config('icap.example');
    $client = new IcapClient(
        $config,
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, str_repeat('A', 100));

    $observed = ['phase1' => '', 'phase2' => ''];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use (
        $client,
        $tmp,
        $serverEnd,
        &$observed,
    ) {
        // Drive the fake server on a parallel fiber.
        $server = \Amp\async(static function () use ($serverEnd, &$observed): void {
            // Phase 1 — read until we see the preview's 0\\r\\n\\r\\n
            // terminator, then answer 100 Continue.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $observed['phase1'] = $buf;
            $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\n");

            // Phase 2 — read the chunked-body continuation (also
            // ending in 0\\r\\n\\r\\n), then answer 204 Clean.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $observed['phase2'] = $buf;
            $serverEnd->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
            $serverEnd->close();
        });

        $result = $client->scanFileWithPreview('/svc', $tmp, 32)->await();
        $server->await();

        expect($result->isInfected())->toBeFalse();
    });

    // The pool's connector was called exactly once: preview and
    // continuation share a socket.
    expect($connectorCalls)->toBe(1);

    // Phase 1 must be a real RESPMOD with the Preview / Allow:204
    // headers and a 0\\r\\n\\r\\n terminator.
    expect($observed['phase1'])->toStartWith('RESPMOD ')
        ->and($observed['phase1'])->toContain('Preview: 32')
        ->and($observed['phase1'])->toContain('Allow: 204')
        ->and($observed['phase1'])->toContain("0\r\n\r\n");

    // Phase 2 must NOT be a new ICAP request — it's only the
    // chunked body continuation, terminated by 0\\r\\n\\r\\n.
    expect($observed['phase2'])->not->toContain('RESPMOD ')
        ->and($observed['phase2'])->not->toContain('ICAP/1.0')
        ->and($observed['phase2'])->toContain("0\r\n\r\n");

    unlink($tmp);
});

/**
 * v2.1.2 regression: the continuation path must stream the remainder
 * through ChunkedBodyEncoder::encodeRemainderFromStream() rather than
 * buffering the entire post-preview body via stream_get_contents().
 *
 * This test uses a 128 KiB file with a 32-byte preview. If the old
 * stream_get_contents() path were still in place the body would be
 * loaded as a single string; the streaming path emits multiple 8 KiB
 * chunks. We verify that phase 2 contains correct chunked-transfer
 * framing and the total decoded length matches the remainder.
 */
it('streams the body remainder in chunks without buffering the entire post-preview payload', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();

    $connectorCalls = 0;
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use ($clientEnd, &$connectorCalls) {
            $connectorCalls++;
            return $clientEnd;
        },
    );
    $config = new Config('icap.example');
    $client = new IcapClient(
        $config,
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    $previewSize = 32;
    $fileSize = 128 * 1024; // 128 KiB
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, str_repeat('B', $fileSize));

    $observed = ['phase2' => ''];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use (
        $client,
        $tmp,
        $previewSize,
        $serverEnd,
        &$observed,
    ) {
        $server = \Amp\async(static function () use ($serverEnd, &$observed): void {
            // Phase 1 — read the preview request until we see the
            // preview terminator, then answer 100 Continue.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\n");

            // Phase 2 — read the chunked-body continuation.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $observed['phase2'] = $buf;
            $serverEnd->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
            $serverEnd->close();
        });

        $result = $client->scanFileWithPreview('/svc', $tmp, $previewSize)->await();
        $server->await();

        expect($result->isInfected())->toBeFalse();
    });

    expect($connectorCalls)->toBe(1);

    // Phase 2 must be valid chunked-transfer with the correct total
    // payload length (fileSize - previewSize).
    $remainder = $fileSize - $previewSize;
    $phase2 = $observed['phase2'];

    // Decode all chunks and verify total length.
    $decoded = '';
    $pos = 0;
    while ($pos < strlen($phase2)) {
        $eol = strpos($phase2, "\r\n", $pos);
        if ($eol === false) {
            break;
        }
        $hexLen = substr($phase2, $pos, $eol - $pos);
        $chunkLen = (int) hexdec($hexLen);
        if ($chunkLen === 0) {
            break;
        }
        $decoded .= substr($phase2, $eol + 2, $chunkLen);
        $pos = $eol + 2 + $chunkLen + 2; // skip data + trailing \r\n
    }
    expect(strlen($decoded))->toBe($remainder);
    // All bytes must be 'B' (our test payload).
    expect($decoded)->toBe(str_repeat('B', $remainder));

    // Must end with the zero-terminator.
    expect($phase2)->toEndWith("0\r\n\r\n");

    // Must NOT be a new ICAP request.
    expect($phase2)->not->toContain('RESPMOD ')
        ->and($phase2)->not->toContain('ICAP/1.0');

    unlink($tmp);
});

/**
 * M06 — a socket goes back to the pool only when the whole exchange was
 * valid and the server did not announce a close. Each case ends with the
 * pool opening a new connection, except the two plain 204 controls, which
 * prove that a clean exchange is still reused.
 */
it('returns the socket to the pool only after a validated exchange', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $interim = "ICAP/1.0 100 Continue\r\n\r\n";
    $cases = [
        // label => [payload, replies in order, exception class, socket reused]
        'control: 204 to the preview'                    => ['abcdefgh', [$clean], null, true],
        'control: 204 after ieof'                        => ['abc', [$clean], null, true],
        '204 to the preview, Connection: close'          => ['abcdefgh', ["ICAP/1.0 204 No Content\r\nConnection: close\r\nEncapsulated: null-body=0\r\n\r\n"], null, false],
        '204 after ieof, connection: Close'              => ['abc', ["ICAP/1.0 204 No Content\r\nconnection: Close\r\nEncapsulated: null-body=0\r\n\r\n"], null, false],
        'final 204, Connection: keep-alive, close'       => ['abcdefgh', [$interim, "ICAP/1.0 204 No Content\r\nConnection: keep-alive, close\r\nEncapsulated: null-body=0\r\n\r\n"], null, false],
        'interim 100, Connection: close'                 => ['abcdefgh', ["ICAP/1.0 100 Continue\r\nConnection: close\r\n\r\n", $clean], null, false],
        'malformed header line'                          => ['abcdefgh', ["ICAP/1.0 204 No Content\r\nno colon here\r\nEncapsulated: null-body=0\r\n\r\n"], IcapMalformedResponseException::class, false],
        '100 Continue after ieof'                        => ['abc', [$interim], IcapProtocolException::class, false],
        'unexpected status after the continuation'       => ['abcdefgh', [$interim, "ICAP/1.0 302 Found\r\nEncapsulated: null-body=0\r\n\r\n"], IcapProtocolException::class, false],
        'server error after the continuation'            => ['abcdefgh', [$interim, "ICAP/1.0 500 Server Error\r\nEncapsulated: null-body=0\r\n\r\n"], IcapServerException::class, false],
    ];

    foreach ($cases as $case => [$payload, $replies, $exception, $reused]) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $connections = 0;
        $pool = new AmpConnectionPool(
            connector: function () use ($clientEnd, &$connections) {
                $connections++;
                return $clientEnd;
            },
        );
        $config = new Config('icap.example', streamTimeout: 2.0);
        $client = new IcapClient($config, new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());

        $tmp = tempnam(sys_get_temp_dir(), 'icap');
        file_put_contents($tmp, $payload);

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp, $serverEnd, $replies, $exception, $case) {
                $server = \Amp\async(static function () use ($serverEnd, $replies): void {
                    foreach ($replies as $reply) {
                        // The request, or its continuation, ends with the zero chunk.
                        $buf = '';
                        while (!str_ends_with($buf, "0\r\n\r\n") && !str_ends_with($buf, "ieof\r\n\r\n")) {
                            $chunk = $serverEnd->read();
                            if ($chunk === null) {
                                return;
                            }
                            $buf .= $chunk;
                        }
                        $serverEnd->write($reply);
                    }
                });

                if ($exception === null) {
                    expect($client->scanFileWithPreview('/svc', $tmp, 4)->await()->isInfected())->toBeFalse($case);
                } else {
                    expect(fn () => $client->scanFileWithPreview('/svc', $tmp, 4)->await())
                        ->toThrow($exception, '', $case);
                }
                $server->await();
            });

            // A released socket stays open in the pool and comes straight
            // back; any other one is closed and the pool connects anew. The
            // closed state is checked itself: a socket left open and
            // unreferenced would pass the connection count as well.
            expect($clientEnd->isClosed())->toBe(!$reused, $case);
            $pool->acquire($config);
            expect($connections)->toBe($reused ? 1 : 2, $case);
        } finally {
            @unlink($tmp);
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    }
});

/**
 * `Preview: 0` on the real wire (RFC 3507 §4.5): the preview request carries
 * its body section with nothing in it, so the server can answer `100 Continue`
 * for a message it knows has a body, and the whole file follows after that.
 */
it('sends a zero-byte preview with its body section, then the whole file after 100 Continue', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();
    $pool = new AmpConnectionPool(connector: fn () => $clientEnd);
    $client = new IcapClient(
        new Config('icap.example', streamTimeout: 2.0),
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdefgh');
    $observed = ['preview' => '', 'rest' => ''];

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverEnd, &$observed) {
            $server = \Amp\async(static function () use ($serverEnd, &$observed): void {
                $buf = '';
                while (!str_ends_with($buf, "\r\n\r\n0\r\n\r\n")) {
                    $chunk = $serverEnd->read();
                    if ($chunk === null) {
                        return;
                    }
                    $buf .= $chunk;
                }
                $observed['preview'] = $buf;
                $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\n");

                $buf = '';
                while (!str_ends_with($buf, "0\r\n\r\n")) {
                    $chunk = $serverEnd->read();
                    if ($chunk === null) {
                        return;
                    }
                    $buf .= $chunk;
                }
                $observed['rest'] = $buf;
                $serverEnd->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
            });

            expect($client->scanFileWithPreview('/svc', $tmp, 0)->await()->isInfected())->toBeFalse();
            $server->await();
        });

        expect($observed['preview'])->toContain("Preview: 0\r\n");
        expect($observed['preview'])->toContain("Content-Length: 8\r\n");
        expect($observed['preview'])->toContain('res-body=');
        expect($observed['preview'])->not->toContain('null-body');
        expect($observed['rest'])->toBe("8\r\nabcdefgh\r\n0\r\n\r\n");
    } finally {
        @unlink($tmp);
        $clientEnd->close();
        $serverEnd->close();
        $pool->close();
    }
});

/**
 * B08, M07 — the continuation ends in its terminator only when the end of the
 * source was observed and matches what the handle announced. A read that
 * fails or stands still, or a source of another length, closes the socket
 * with the body unterminated; before, the server got the shortened rest
 * together with its `0\r\n\r\n` and could answer 204 for a file it had only
 * seen in part.
 */
it('does not terminate the continuation of a source that failed or changed size', function () {
    $cont = "ICAP/1.0 100 Continue\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $data = str_repeat('R', 20001);

    $cases = [
        // label => [source, error expected]
        'control: the whole file'              => [fn () => ScriptedSource::add('s', $data), false],
        'read error in the rest'               => [fn () => ScriptedSource::add('s', $data, failAfter: 10000), true],
        'no progress in the rest'              => [fn () => ScriptedSource::add('s', $data, stallAfter: 10000), true],
        'more bytes than the handle reported'  => [fn () => ScriptedSource::add('s', $data, size: 15001), true],
        'fewer bytes than the handle reported' => [fn () => ScriptedSource::add('s', $data, size: 25001), true],
    ];

    foreach ($cases as $case => [$source, $fails]) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $pool = new AmpConnectionPool(connector: fn () => $clientEnd);
        $client = new IcapClient(
            new Config('icap.example', streamTimeout: 2.0),
            new AsyncAmpTransport($pool),
            new RequestFormatter(),
            new ResponseParser(),
        );
        $rest = '';

        try {
            $path = $source();
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $path, $serverEnd, $cont, $clean, $fails, $case, &$rest) {
                $server = \Amp\async(static function () use ($serverEnd, $cont, $clean, &$rest): void {
                    $buf = '';
                    while (!str_ends_with($buf, "4\r\nRRRR\r\n0\r\n\r\n")) {
                        $chunk = $serverEnd->read();
                        if ($chunk === null) {
                            return;
                        }
                        $buf .= $chunk;
                    }
                    $serverEnd->write($cont);

                    // The rest, until the client's terminator or its close.
                    while (($chunk = $serverEnd->read()) !== null) {
                        $rest .= $chunk;
                        if (str_ends_with($rest, "0\r\n\r\n")) {
                            $serverEnd->write($clean);
                            return;
                        }
                    }
                });

                if ($fails) {
                    expect(fn () => $client->scanFileWithPreview('/svc', $path, 4)->await())
                        ->toThrow(RuntimeException::class, '', $case);
                } else {
                    expect($client->scanFileWithPreview('/svc', $path, 4)->await()->isInfected())->toBeFalse($case);
                }
                $server->await();
            });

            // The wire and the handles as they are, before any cleanup.
            expect(str_ends_with($rest, "0\r\n\r\n"))->toBe(!$fails, $case);
            expect($clientEnd->isClosed())->toBe($fails, $case);
            expect(ScriptedSource::$opened)->toBe(1, $case);
            expect(ScriptedSource::$closed)->toBe(1, $case);
        } finally {
            ScriptedSource::reset();
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    }
});

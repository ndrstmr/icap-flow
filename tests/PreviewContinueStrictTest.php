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
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\FileWorkers;
use Ndrstmr\Icap\Tests\IcapTestServer;
use Ndrstmr\Icap\Tests\OneRequestTransport;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

uses(AsyncTestCase::class);

foreach ([
    [400, IcapClientException::class, PreviewDecision::ABORT_CLEAN, 0],
    [499, IcapClientException::class, PreviewDecision::ABORT_CLEAN, 0],
    [500, IcapServerException::class, PreviewDecision::ABORT_CLEAN, 0],
    [599, IcapServerException::class, PreviewDecision::ABORT_CLEAN, 0],
    [400, IcapClientException::class, PreviewDecision::CONTINUE_SENDING, 0],
    [499, IcapClientException::class, PreviewDecision::CONTINUE_SENDING, 0],
    [500, IcapServerException::class, PreviewDecision::CONTINUE_SENDING, 0],
    [599, IcapServerException::class, PreviewDecision::CONTINUE_SENDING, 0],
    [101, IcapProtocolException::class, PreviewDecision::ABORT_CLEAN, 0],
    [302, IcapProtocolException::class, PreviewDecision::ABORT_CLEAN, 0],
    [600, IcapProtocolException::class, PreviewDecision::ABORT_CLEAN, 0],
    [100, IcapProtocolException::class, PreviewDecision::ABORT_CLEAN, 1],
    [100, IcapProtocolException::class, PreviewDecision::ABORT_INFECTED, 1],
    [200, IcapProtocolException::class, PreviewDecision::CONTINUE_SENDING, 1],
    [204, IcapProtocolException::class, PreviewDecision::CONTINUE_SENDING, 1],
    [206, IcapProtocolException::class, PreviewDecision::CONTINUE_SENDING, 1],
] as [$status, $exception, $decision, $calls]) {
    it('rejects preview ' . $status . ' with ' . $decision->value . ' and closes the session', function () use ($status, $exception, $decision, $calls) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $pool = new AmpConnectionPool(connector: fn () => $clientEnd);
        $strategy = new class ($decision) implements PreviewStrategyInterface {
            public int $calls = 0;

            public function __construct(private readonly PreviewDecision $decision)
            {
            }

            public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
            {
                $this->calls++;
                return $this->decision;
            }
        };
        $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser(), $strategy);
        $tmp = tempnam(sys_get_temp_dir(), 'icap');
        file_put_contents($tmp, 'abcdefgh');

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp, $serverEnd, $status, $exception, $strategy, $calls): void {
                $server = \Amp\async(static function () use ($serverEnd, $status): string {
                    $timeout = new \Amp\TimeoutCancellation(2.0);
                    $preview = '';
                    while (!str_ends_with($preview, "0\r\n\r\n")) {
                        $chunk = $serverEnd->read($timeout);
                        if ($chunk === null) {
                            throw new RuntimeException('Connection closed before the preview.');
                        }
                        $preview .= $chunk;
                    }
                    $serverEnd->write("ICAP/1.0 {$status} Response\r\nEncapsulated: null-body=0\r\n\r\n");
                    $after = '';
                    while (($chunk = $serverEnd->read($timeout)) !== null) {
                        $after .= $chunk;
                    }
                    return $after;
                });
                $server->ignore();

                expect(fn () => $client->scanFileWithPreview('/svc', $tmp, 4)->await())->toThrow($exception);
                expect($strategy->calls)->toBe($calls);
                expect($server->await())->toBe('');
            });
            expect($clientEnd->isClosed())->toBeTrue();
        } finally {
            @unlink($tmp);
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    });
}

/*
 * A preview that the server answers with 100 Continue goes on over the connection it began on. A transport without
 * sessions cannot do that, and the client does not send the file again in a request of its own: it refuses the
 * preview at the call, before a file is opened or a connection is made. Whatever needs no preview works with it.
 */
it('refuses a preview on a transport without sessions before a file is opened or a connection is made', function () {
    $server = IcapTestServer::start(static fn (array $request) => [IcapTestServer::CLEAN]);
    try {
        // The file does not exist: had the client got as far as opening it, the error would be an IcapStorageException.
        $missing = sys_get_temp_dir() . '/icap-preview-missing-' . bin2hex(random_bytes(6));
        $client = new IcapClient(new Config('127.0.0.1', $server->port), new OneRequestTransport(), new RequestFormatter(), new ResponseParser());

        expect(fn () => $client->scanFileWithPreview('/avscan', $missing, 1024))->toThrow(\LogicException::class, 'SessionAwareTransport')
            ->and(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $missing, 1 << 20, 1024))->toThrow(\LogicException::class, 'SessionAwareTransport')
            // The decorators pass it on: the retrying one fails its Future, the synchronous one throws.
            ->and(fn () => (new RetryingIcapClient($client))->scanFileWithPreview('/avscan', $missing, 1024)->await())->toThrow(\LogicException::class, 'SessionAwareTransport')
            ->and(fn () => (new SynchronousIcapClient($client))->scanFileWithPreview('/avscan', $missing, 1024))->toThrow(\LogicException::class, 'SessionAwareTransport');
        expect($server->connections)->toBe(0);

        // A scan without a preview needs no session.
        $path = FileWorkers::file(str_repeat('a', 5000));
        expect($client->scanFile('/avscan', $path)->await()->isInfected())->toBeFalse()
            ->and($server->connections)->toBe(1);
    } finally {
        FileWorkers::removeFiles();
        $server->stop();
    }
});

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
        // N03: a failure status to the preview is classified as it is to every other answer.
        'server error to the preview'                    => ['abcdefgh', ["ICAP/1.0 503 Service Unavailable\r\n\r\n"], IcapServerException::class, false],
        'client error to the preview'                    => ['abcdefgh', ["ICAP/1.0 404 Not Found\r\n\r\n"], IcapClientException::class, false],
        'server error after ieof'                        => ['abc', ["ICAP/1.0 503 Service Unavailable\r\n\r\n"], IcapServerException::class, false],
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
                        ->toThrow(IcapStorageException::class, '', $case);
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

/*
 * B02 — a response is matched to its request by the order of arrival, so where the framing ends
 * a response decides which bytes the next scan reads. The evidence of the audit sent a chunk
 * payload with a terminator and a forged 204 in it: the framing stopped at the terminator, and
 * the rest of the payload, with the forged answer, was read as the answer to the next scan.
 * These tests drive two scans over one socket from the pool, with real sockets.
 */
it('answers the second scan with its own response, not with bytes from the payload of the first', function () {
    $forged = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $payload = "prefix\r\n0\r\n\r\n" . $forged . 'suffix';
    $head = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($payload) . "\r\n\r\n";
    $first = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head
        . dechex(strlen($payload)) . "\r\n" . $payload . "\r\n0\r\n\r\n";
    // The first part of the reply ends right behind the terminator in the payload.
    $cut = strpos($first, 'prefix') + strlen("prefix\r\n0\r\n\r\n");
    $blocked = "HTTP/1.1 403 Blocked\r\nContent-Length: 0\r\n\r\n";
    $infected = "ICAP/1.0 200 OK\r\nX-Virus-Name: EICAR\r\nEncapsulated: res-hdr=0, res-body=" . strlen($blocked) . "\r\n\r\n" . $blocked . "0\r\n\r\n";

    [$serverEnd, $clientEnd] = Socket\createSocketPair();
    $connections = 0;
    $pool = new AmpConnectionPool(
        connector: function () use ($clientEnd, &$connections) {
            $connections++;
            return $clientEnd;
        },
    );
    $client = new IcapClient(new Config('icap.example', streamTimeout: 2.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverEnd, $first, $cut, $infected, $payload, &$connections) {
            $server = \Amp\async(static function () use ($serverEnd, $first, $cut, $infected): void {
                // The reply to the first request comes in two parts, split inside the payload;
                // the reply to the second request comes whole.
                foreach ([[substr($first, 0, $cut), substr($first, $cut)], [$infected]] as $parts) {
                    $buf = '';
                    while (!str_ends_with($buf, "ieof\r\n\r\n")) {
                        $chunk = $serverEnd->read();
                        if ($chunk === null) {
                            return;
                        }
                        $buf .= $chunk;
                    }
                    foreach ($parts as $i => $part) {
                        if ($i > 0) {
                            \Amp\delay(0.05);
                        }
                        $serverEnd->write($part);
                    }
                }
            });

            $one = $client->scanFileWithPreview('/svc', $tmp, 4)->await();
            $two = $client->scanFileWithPreview('/svc', $tmp, 4)->await();
            $server->await();

            expect($one->isInfected())->toBeFalse();
            expect($one->getOriginalResponse()->body)->toBe($payload);
            // The second scan got the answer to the second request, and the socket was reused for it.
            expect($two->isInfected())->toBeTrue();
            expect($two->getVirusName())->toBe('EICAR');
            expect($connections)->toBe(1);
        });
    } finally {
        @unlink($tmp);
        $clientEnd->close();
        $serverEnd->close();
        $pool->close();
    }
});

it('gives no verdict on a socket on which the server sent more than the response, and does not reuse it', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $blocked = "HTTP/1.1 403 Blocked\r\nContent-Length: 0\r\n\r\n";
    $infected = "ICAP/1.0 200 OK\r\nX-Virus-Name: EICAR\r\nEncapsulated: res-hdr=0, res-body=" . strlen($blocked) . "\r\n\r\n" . $blocked . "0\r\n\r\n";

    // The first socket carries a reply and a second, forged one behind it.
    [$serverOne, $clientOne] = Socket\createSocketPair();
    [$serverTwo, $clientTwo] = Socket\createSocketPair();
    $sockets = [$clientOne, $clientTwo];
    $connections = 0;
    $pool = new AmpConnectionPool(
        connector: function () use (&$sockets, &$connections) {
            $connections++;

            return array_shift($sockets) ?? throw new LogicException('The test has no further socket.');
        },
    );
    $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverOne, $serverTwo, $clean, $infected) {
            $answer = static function (Socket\Socket $end, string $reply): \Amp\Future {
                return \Amp\async(static function () use ($end, $reply): void {
                    $buf = '';
                    while (!str_ends_with($buf, "ieof\r\n\r\n")) {
                        $chunk = $end->read();
                        if ($chunk === null) {
                            return;
                        }
                        $buf .= $chunk;
                    }
                    $end->write($reply);
                });
            };
            $first = $answer($serverOne, $clean . $clean);
            $second = $answer($serverTwo, $infected);

            // A clean answer with a second one behind it is no verdict.
            expect(fn () => $client->scanFileWithPreview('/svc', $tmp, 4)->await())
                ->toThrow(IcapMalformedResponseException::class);
            $two = $client->scanFileWithPreview('/svc', $tmp, 4)->await();
            $first->await();
            $second->await();

            expect($two->isInfected())->toBeTrue();
        });

        // The surplus bytes closed the first socket instead of letting it go back to the pool.
        expect($clientOne->isClosed())->toBeTrue();
        expect($connections)->toBe(2);
    } finally {
        @unlink($tmp);
        foreach ([$clientOne, $clientTwo, $serverOne, $serverTwo] as $end) {
            $end->close();
        }
        $pool->close();
    }
});

it('fails the scan and sends no continuation when bytes arrive behind a 100 Continue', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();
    $pool = new AmpConnectionPool(connector: fn () => $clientEnd);
    $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdefgh');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverEnd) {
            $server = \Amp\async(static function () use ($serverEnd): string {
                $buf = '';
                while (!str_ends_with($buf, "0\r\n\r\n")) {
                    $chunk = $serverEnd->read();
                    if ($chunk === null) {
                        return '';
                    }
                    $buf .= $chunk;
                }
                // 100 Continue, and an answer behind it that no request has earned yet.
                $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\nICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");

                // What the client still sends before it closes its end.
                $after = '';
                while (($chunk = $serverEnd->read()) !== null) {
                    $after .= $chunk;
                }

                return $after;
            });

            expect(fn () => $client->scanFileWithPreview('/svc', $tmp, 4)->await())
                ->toThrow(IcapMalformedResponseException::class);
            expect($server->await())->toBe('');
        });

        expect($clientEnd->isClosed())->toBeTrue();
    } finally {
        @unlink($tmp);
        $clientEnd->close();
        $serverEnd->close();
        $pool->close();
    }
});

/*
 * AP10-R02 — the answer to a preview is a bare 100 or 204: neither carries a message. A 100 with a
 * body made the client send the rest of the file and report a clean scan; a 204 with a body kept
 * its socket for the next scan. The answer is refused when its head has arrived: no continuation
 * goes out and the socket is closed.
 */
it('refuses a 100 or 204 that carries a message, sends no continuation and closes the socket', function () {
    $body = "3\r\nabc\r\n0\r\n\r\n";
    $replies = [
        '100 with a res-body' => "ICAP/1.0 100 Continue\r\nEncapsulated: res-body=0\r\n\r\n" . $body,
        '100 with an opt-body' => "ICAP/1.0 100 Continue\r\nEncapsulated: opt-body=0\r\n\r\n" . $body,
        '204 with a res-body' => "ICAP/1.0 204 No Content\r\nEncapsulated: res-body=0\r\n\r\n" . $body,
    ];

    foreach ($replies as $case => $reply) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $pool = new AmpConnectionPool(connector: fn () => $clientEnd);
        $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
        $tmp = tempnam(sys_get_temp_dir(), 'icap');
        file_put_contents($tmp, 'abcdefgh');

        try {
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $tmp, $serverEnd, $reply, $case) {
                $server = \Amp\async(static function () use ($serverEnd, $reply): string {
                    $buf = '';
                    while (!str_ends_with($buf, "0\r\n\r\n")) {
                        $chunk = $serverEnd->read();
                        if ($chunk === null) {
                            return '';
                        }
                        $buf .= $chunk;
                    }
                    $serverEnd->write($reply);

                    // What the client still sends before it closes its end.
                    $after = '';
                    while (($chunk = $serverEnd->read()) !== null) {
                        $after .= $chunk;
                    }

                    return $after;
                });

                expect(fn () => $client->scanFileWithPreview('/svc', $tmp, 4)->await())
                    ->toThrow(IcapMalformedResponseException::class, '', $case);
                expect($server->await())->toBe('', $case);
            });

            expect($clientEnd->isClosed())->toBeTrue($case);
        } finally {
            @unlink($tmp);
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    }
});

/*
 * M05 / G2 — a socket the pool keeps has no one reading it. Bytes that arrive on it after the response
 * (a second answer, a late duplicate) used to be handed to the next scan as its answer, and a socket the
 * server had closed was handed out too. The pool looks at the socket when it hands it out again.
 */
it('answers the second scan with its own response when bytes arrived on the socket the pool kept', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $blocked = "HTTP/1.1 403 Blocked\r\nContent-Length: 0\r\n\r\n";
    $infected = "ICAP/1.0 200 OK\r\nX-Virus-Name: EICAR\r\nEncapsulated: res-hdr=0, res-body=" . strlen($blocked) . "\r\n\r\n" . $blocked . "0\r\n\r\n";

    [$serverOne, $clientOne] = Socket\createSocketPair();
    [$serverTwo, $clientTwo] = Socket\createSocketPair();
    $sockets = [$clientOne, $clientTwo];
    $connections = 0;
    $pool = new AmpConnectionPool(
        connector: function () use (&$sockets, &$connections) {
            $connections++;

            return array_shift($sockets) ?? throw new LogicException('The test has no further socket.');
        },
    );
    $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverOne, $serverTwo, $clean, $infected) {
            $answer = static function (Socket\Socket $end, string $reply): \Amp\Future {
                return \Amp\async(static function () use ($end, $reply): void {
                    $buf = '';
                    while (!str_ends_with($buf, "ieof\r\n\r\n")) {
                        $chunk = $end->read();
                        if ($chunk === null) {
                            return;
                        }
                        $buf .= $chunk;
                    }
                    $end->write($reply);
                });
            };
            $first = $answer($serverOne, $clean);
            $second = $answer($serverTwo, $infected);

            $one = $client->scanFileWithPreview('/svc', $tmp, 4)->await();
            $first->await();

            // The socket is back in the pool. The server sends a clean verdict for a request nobody made.
            $serverOne->write($clean);
            \Amp\delay(0.01);

            $two = $client->scanFileWithPreview('/svc', $tmp, 4)->await();

            expect($one->isInfected())->toBeFalse();
            expect($two->isInfected())->toBeTrue();
            $second->await();
        });

        expect($clientOne->isClosed())->toBeTrue();
        expect($connections)->toBe(2);
    } finally {
        @unlink($tmp);
        foreach ([$clientOne, $clientTwo, $serverOne, $serverTwo] as $end) {
            $end->close();
        }
        $pool->close();
    }
});

it('sends the next scan to a new connection when the server has closed the one the pool kept', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    [$serverOne, $clientOne] = Socket\createSocketPair();
    [$serverTwo, $clientTwo] = Socket\createSocketPair();
    $sockets = [$clientOne, $clientTwo];
    $connections = 0;
    $pool = new AmpConnectionPool(
        connector: function () use (&$sockets, &$connections) {
            $connections++;

            return array_shift($sockets) ?? throw new LogicException('The test has no further socket.');
        },
    );
    $client = new IcapClient(new Config('icap.example', streamTimeout: 1.0), new AsyncAmpTransport($pool), new RequestFormatter(), new ResponseParser());
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $serverOne, $serverTwo, $clean) {
            $answer = static function (Socket\Socket $end, bool $thenClose) use ($clean): \Amp\Future {
                return \Amp\async(static function () use ($end, $thenClose, $clean): void {
                    $buf = '';
                    while (!str_ends_with($buf, "ieof\r\n\r\n")) {
                        $chunk = $end->read();
                        if ($chunk === null) {
                            return;
                        }
                        $buf .= $chunk;
                    }
                    $end->write($clean);
                    if ($thenClose) {
                        $end->close(); // a server that drops an idle connection
                    }
                });
            };
            $first = $answer($serverOne, true);
            $second = $answer($serverTwo, false);

            expect($client->scanFileWithPreview('/svc', $tmp, 4)->await()->isInfected())->toBeFalse();
            $first->await();
            \Amp\delay(0.01);

            // The second scan neither fails on the dead connection nor is sent into it.
            expect($client->scanFileWithPreview('/svc', $tmp, 4)->await()->isInfected())->toBeFalse();
            $second->await();
        });

        expect($clientOne->isClosed())->toBeTrue();
        expect($connections)->toBe(2);
    } finally {
        @unlink($tmp);
        foreach ([$clientOne, $clientTwo, $serverOne, $serverTwo] as $end) {
            $end->close();
        }
        $pool->close();
    }
});

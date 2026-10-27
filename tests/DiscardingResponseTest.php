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
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Mockery;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DefaultPreviewStrategy;
use Ndrstmr\Icap\DiscardingIcapClient;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\IcapClientInterface;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\DiscardingTransport;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\TransportSession;

use function Amp\async;
use function Amp\delay;

/*
 * AP-15 (M02): a server that is not offered `Allow: 204` has to send the whole message back (RFC 3507 §4.6),
 * so the answer to a large file is as large as the file. The scan helpers with a discarding body receive such
 * an answer, check it to its last byte and keep only its size. These tests use a real socket and a local
 * ICAP server; the decoder and the reader are tested with their own corpora, the memory in the test of the
 * gigabyte (ResponseStreamingTest).
 */

afterEach(function (): void {
    FileWorkers::removeFiles();
});

/** The size of a response over the 10 MiB that a configuration accepts by default. */
const DISCARD_OVER_LIMIT = 12 * 1024 * 1024;

function discardClient(IcapTestServer $server, ?TransportInterface $transport = null, float $streamTimeout = 30.0, float $operationTimeout = 60.0): IcapClient
{
    return new IcapClient(
        new Config('127.0.0.1', $server->port, streamTimeout: $streamTimeout, operationTimeout: $operationTimeout),
        $transport ?? new AsyncAmpTransport(),
        new RequestFormatter(),
        new ResponseParser(),
    );
}

/**
 * The whole response of {@see IcapTestServer::echo()} as the parts it is written in.
 *
 * @return list<string>
 */
function discardParts(int $bytes): array
{
    return iterator_to_array(IcapTestServer::echo($bytes), false);
}

it('receives an echo of more than the response limit without keeping it, and leaves the other mode as it is', function () {
    $size = DISCARD_OVER_LIMIT;
    $server = IcapTestServer::start(static fn (array $request) => IcapTestServer::echo($request['bodyBytes']));
    try {
        $client = discardClient($server);
        $path = FileWorkers::sparseFile($size);

        // No Allow: 204 is offered, so the server returns the message.
        $result = $client->scanFileDiscardingBody('/avscan', $path, 2 * $size)->await();
        $response = $result->getOriginalResponse();

        expect($result->isInfected())->toBeFalse()
            ->and($response->statusCode)->toBe(200)
            ->and($response->body)->toBe('')
            ->and($response->discardedBodyBytes)->toBe($size)
            ->and($server->requests[0]['bodyBytes'])->toBe($size)
            ->and($server->requests[0]['headers'])->not->toHaveKey('allow');

        // The budget counts the bytes of the response, and is not a figure to be passed.
        expect(fn () => $client->scanFileDiscardingBody('/avscan', $path, $size)->await())
            ->toThrow(IcapMalformedResponseException::class, 'max size');

        // The mode that keeps the body keeps its body and its limit.
        expect(fn () => $client->scanFile('/avscan', $path)->await())->toThrow(IcapMalformedResponseException::class, 'max size');
        $kept = $client->scanFile('/avscan', FileWorkers::file(str_repeat('a', 1000)))->await()->getOriginalResponse();
        expect($kept->body)->toBe(str_repeat('x', 1000))
            ->and($kept->discardedBodyBytes)->toBeNull();
    } finally {
        $server->stop();
    }
});

/**
 * Not one transport: the session of the async transport carries the preview and the rest of the file on one socket
 * (RFC 3507 §4.5), a transport without sessions sends the file again in a request of its own.
 */
final class OneRequestTransport implements DiscardingTransport
{
    public function __construct(private readonly AsyncAmpTransport $inner = new AsyncAmpTransport())
    {
    }

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        return $this->inner->request($config, $rawRequest, $cancellation);
    }

    #[\Override]
    public function requestDiscardingBody(Config $config, iterable $rawRequest, int $maxResponseBytes, ?Cancellation $cancellation = null): Future
    {
        return $this->inner->requestDiscardingBody($config, $rawRequest, $maxResponseBytes, $cancellation);
    }
}

it('scans with and without preview when the server answers with its whole message or with a 204', function () {
    $size = DISCARD_OVER_LIMIT;
    $echo = static fn (array $r): \Generator => IcapTestServer::echo($size);
    $clean = static fn (array $r): array => [IcapTestServer::CLEAN];
    $continue = static fn (array $r): array => [IcapTestServer::CONTINUE];

    // label => [answer to the preview, answer to the rest of the file, bytes of the body that are discarded, preview is the whole file]
    $scenarios = [
        'preview, then a 200 at once'     => [$echo, null, $size, false],
        'preview, 100 Continue, then 200' => [$continue, $echo, $size, false],
        'preview, then a 204 at once'     => [$clean, null, 0, false],
        'preview, 100 Continue, then 204' => [$continue, $clean, 0, false],
        'file inside the preview, 200'    => [$echo, null, $size, true],
    ];

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $how => $sessions) {
        foreach ($scenarios as $label => [$previewAnswer, $restAnswer, $discarded, $whole]) {
            $server = IcapTestServer::start(static fn (array $r) => $r['stage'] === 'preview' ? $previewAnswer($r) : ($restAnswer ?? $clean)($r));
            try {
                $client = discardClient($server, $sessions ? new AsyncAmpTransport() : new OneRequestTransport());
                $path = FileWorkers::file($whole ? str_repeat('a', 500) : str_repeat('a', 5000));

                $result = $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 2 * $size, 1024)->await();

                // After `100 Continue` the rest of the file goes on the same socket, or in a request of its own.
                $followUp = $restAnswer !== null;
                expect($result->isInfected())->toBeFalse("{$how}: {$label}")
                    ->and($result->getOriginalResponse()->discardedBodyBytes)->toBe($discarded, "{$how}: {$label}")
                    ->and($result->getOriginalResponse()->body)->toBe('', "{$how}: {$label}")
                    ->and($server->requests[0]['headers']['preview'])->toBe('1024', "{$how}: {$label}")
                    ->and(array_column($server->requests, 'stage'))->toBe($followUp ? ['preview', $sessions ? 'rest' : 'single'] : ['preview'], "{$how}: {$label}")
                    ->and($server->connections)->toBe($followUp && !$sessions ? 2 : 1, "{$how}: {$label}");
            } finally {
                $server->stop();
            }
        }
    }

    // A scan without preview and a 204: nothing to discard, and not an empty body either.
    $server = IcapTestServer::start(static fn (array $r) => $clean($r));
    try {
        $result = discardClient($server)->scanFileDiscardingBody('/avscan', FileWorkers::file('abc'), 1024)->await();
        expect($result->getOriginalResponse()->statusCode)->toBe(204)
            ->and($result->getOriginalResponse()->discardedBodyBytes)->toBe(0);
    } finally {
        $server->stop();
    }
});

it('takes the verdict from the headers of a response whose body is not kept', function () {
    $server = IcapTestServer::start(static fn (array $r) => IcapTestServer::echo(300_000, icapHeaders: "X-Virus-Name: Eicar-Test\r\n"));
    try {
        $client = discardClient($server);
        $path = FileWorkers::file(str_repeat('a', 300_000));

        foreach ([
            'no preview' => $client->scanFileDiscardingBody('/avscan', $path, 1 << 20),
            'preview'    => $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024),
        ] as $how => $scan) {
            $result = $scan->await();
            expect($result->isInfected())->toBeTrue($how)
                ->and($result->getVirusName())->toBe('Eicar-Test', $how)
                ->and($result->getOriginalResponse()->discardedBodyBytes)->toBe(300_000, $how);
        }
    } finally {
        $server->stop();
    }
});

it('refuses a response that is forged, cut or illegal at its end, however large, and does not offer the socket again', function () {
    $size = 2 * 1024 * 1024;
    $payload = "data\r\n0\r\n\r\nICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $head = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
    $icap = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head;

    $parts = discardParts($size);
    $last = count($parts) - 1;
    $withLast = static fn (string $replacement): array => [...array_slice($parts, 0, $last), $replacement];
    $lastData = $last - 1;

    // label => [parts of the response, the exception it ends in or null if it is a legal response, bytes of the body, whether the socket may be used again]
    $variants = [
        'a legal response'                            => [$parts, null, $size, true],
        'a terminator and a forged response in the body' => [[$icap, dechex(strlen($payload)) . "\r\n" . $payload . "\r\n", "0\r\n\r\n"], null, strlen($payload), true],
        'bytes behind the response'                   => [[...array_slice($parts, 0, $last), $parts[$last] . 'ICAP/1.0 204 No Con'], IcapMalformedResponseException::class, null, false],
        'a trailer that is no header field'           => [$withLast("0\r\nnot a field\r\n\r\n"), IcapMalformedResponseException::class, null, false],
        'a last chunk without the blank line'         => [[...array_slice($parts, 0, $last), "0\r\n", IcapTestServer::CLOSE], IcapMalformedResponseException::class, null, false],
        'no CRLF behind the last data'                => [[...array_slice($parts, 0, $lastData), substr($parts[$lastData], 0, -2) . 'ZZ', $parts[$last]], IcapMalformedResponseException::class, null, false],
        'the stream ends inside the body'             => [[...array_slice($parts, 0, 12), IcapTestServer::CLOSE], IcapMalformedResponseException::class, null, false],
        'a chunk size that is no number'              => [[$parts[0], "zz\r\nabc\r\n", $parts[$last]], IcapMalformedResponseException::class, null, false],
    ];

    foreach ($variants as $label => [$response, $failure, $body, $reusable]) {
        // The rest of the file is answered with the response under test; the socket is the one of the preview.
        $server = IcapTestServer::start(static fn (array $r) => $r['stage'] === 'preview' ? [IcapTestServer::CONTINUE] : $response);
        $pool = new AmpConnectionPool();
        try {
            $client = discardClient($server, new AsyncAmpTransport($pool));
            $path = FileWorkers::file(str_repeat('a', 5000));
            $scan = static fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 8 * $size, 1024)->await();

            if ($failure !== null) {
                expect($scan)->toThrow($failure, '', $label);
            } else {
                expect($scan()->getOriginalResponse()->discardedBodyBytes)->toBe($body, $label);
            }

            // A second scan finds the first socket again only if the first exchange was a legal one to its end.
            $server->requests = [];
            try {
                $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 8 * $size, 1024)->await();
            } catch (IcapMalformedResponseException) {
                // The same server answers the same way.
            }
            expect($server->connections)->toBe($reusable ? 1 : 2, $label);
        } finally {
            $pool->close();
            $server->stop();
        }
    }
});

it('refuses bytes the server sent behind a response, whichever response of the exchange it is', function () {
    $stray = 'ICAP/1.0 204 No Con';
    // The end of the response and the bytes behind it go out in one write, after the rest has been read: they come in together.
    $echoBehind = static function (array $r) use ($stray): \Generator {
        $parts = discardParts(64 * 1024);
        $last = array_pop($parts);
        yield from $parts;
        delay(0.05);
        yield $last . $stray;
    };
    $cleanBehind = static fn (array $r): array => [IcapTestServer::CLEAN . $stray];
    $continue = static fn (array $r): array => [IcapTestServer::CONTINUE];
    $continueBehind = static fn (array $r): array => [IcapTestServer::CONTINUE . $stray];

    // label => [answer to the preview, answer to the rest of the file, preview is the whole file, whether the rest of the file is sent]
    $scenarios = [
        'preview, then a 200 at once'                 => [$echoBehind, null, false, false],
        'preview, then a 204 at once'                 => [$cleanBehind, null, false, false],
        'file inside the preview, 200'                => [$echoBehind, null, true, false],
        'preview, 100 Continue with bytes behind it'  => [$continueBehind, null, false, false],
        'preview, 100 Continue, then 200'             => [$continue, $echoBehind, false, true],
        'preview, 100 Continue, then 204'             => [$continue, $cleanBehind, false, true],
    ];

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $how => $sessions) {
        foreach ($scenarios as $label => [$previewAnswer, $restAnswer, $whole, $sent]) {
            $server = IcapTestServer::start(static fn (array $r) => $r['stage'] === 'preview' ? $previewAnswer($r) : ($restAnswer ?? $cleanBehind)($r));
            try {
                $client = discardClient($server, $sessions ? new AsyncAmpTransport() : new OneRequestTransport());
                $path = FileWorkers::file($whole ? str_repeat('a', 500) : str_repeat('a', 5000));

                expect(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await())
                    ->toThrow(IcapMalformedResponseException::class, '', "{$how}: {$label}");
                // Nothing follows a response that has bytes behind it: the rest of the file is not sent after a 100 like that.
                expect(array_column($server->requests, 'stage'))->toBe($sent ? ['preview', $sessions ? 'rest' : 'single'] : ['preview'], "{$how}: {$label}");
            } finally {
                $server->stop();
            }
        }
    }

    foreach (['a 200' => $echoBehind, 'a 204' => $cleanBehind] as $label => $answer) {
        $server = IcapTestServer::start($answer);
        try {
            expect(fn () => discardClient($server)->scanFileDiscardingBody('/avscan', FileWorkers::file('abc'), 1 << 20)->await())
                ->toThrow(IcapMalformedResponseException::class, '', $label);
        } finally {
            $server->stop();
        }
    }
});

it('refuses a preview strategy of its own, which would judge a body that is not there', function () {
    // The verdict of this strategy is in the body of the answer to the preview.
    $own = new class () implements PreviewStrategyInterface {
        #[\Override]
        public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
        {
            return str_contains($previewResponse->body, 'VERDICT-INFECTED') ? PreviewDecision::ABORT_INFECTED : PreviewDecision::ABORT_CLEAN;
        }
    };
    $body = 'VERDICT-INFECTED';
    $head = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n";
    $answer = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head . dechex(strlen($body)) . "\r\n" . $body . "\r\n0\r\n\r\n";
    $server = IcapTestServer::start(static fn (array $r) => [$answer]);
    try {
        $path = FileWorkers::file(str_repeat('a', 5000));
        $missing = sys_get_temp_dir() . '/icap-discard-missing-' . bin2hex(random_bytes(6));
        $clientOf = static fn (TransportInterface $transport, ?PreviewStrategyInterface $strategy): IcapClient => new IcapClient(
            new Config('127.0.0.1', $server->port),
            $transport,
            new RequestFormatter(),
            new ResponseParser(),
            $strategy,
        );

        // Where the body is kept, the strategy sees the body.
        expect($clientOf(new AsyncAmpTransport(), $own)->scanFileWithPreview('/avscan', $path, 1024)->await()->isInfected())->toBeTrue();

        foreach (['one socket for preview and rest' => new AsyncAmpTransport(), 'one request per message' => new OneRequestTransport()] as $how => $transport) {
            $client = $clientOf($transport, $own);
            $connections = $server->connections;
            // Told at once, before a file is opened (this one does not exist) or a byte is sent.
            expect(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $missing, 1 << 20, 1024))->toThrow(\LogicException::class, '', $how)
                ->and(fn () => (new RetryingIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $missing, 1 << 20, 1024)->await())->toThrow(\LogicException::class, '', $how)
                ->and(fn () => (new SynchronousIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $missing, 1 << 20, 1024))->toThrow(\LogicException::class, '', $how);
            expect($server->connections)->toBe($connections, $how);

            // The strategy of the library, with the headers of the configuration or its own, only reads status and headers.
            foreach ([null, new DefaultPreviewStrategy(['X-Virus-Name'])] as $strategy) {
                $result = $clientOf($transport, $strategy)->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await();
                expect($result->getOriginalResponse()->discardedBodyBytes)->toBe(strlen($body), $how);
            }
        }
    } finally {
        $server->stop();
    }
});

it('ends the receipt of a large body with the cancellation of the caller, the time of the operation and the stream timeout', function () {
    // The server has sent a few chunks and is slow with the rest: 0.1 s for each chunk, more than a second in all.
    $slow = static function (array $r): \Generator {
        foreach (discardParts(4 * 1024 * 1024) as $i => $part) {
            yield $part;
            if ($i > 0 && $i < 12) {
                delay(0.1);
            }
        }
    };
    $answer = static fn (array $r) => $r['stage'] === 'preview' ? [IcapTestServer::CONTINUE] : $slow($r);

    $cases = [
        'cancelled by the caller'   => [0.15, 30.0, 60.0, CancelledException::class],
        'operation timeout'         => [null, 30.0, 0.4, IcapTimeoutException::class],
        // Bytes keep coming, so no idle timer would fire: the stream timeout is a maximum for the whole answer.
        'stream timeout'            => [null, 0.4, 60.0, IcapTimeoutException::class],
    ];

    foreach ($cases as $label => [$cancelAfter, $streamTimeout, $operationTimeout, $failure]) {
        $server = IcapTestServer::start($answer);
        $pool = new AmpConnectionPool();
        try {
            $client = discardClient($server, new AsyncAmpTransport($pool), $streamTimeout, $operationTimeout);
            $path = FileWorkers::file(str_repeat('a', 5000));
            $cancel = new DeferredCancellation();
            $timer = $cancelAfter === null ? null : \Revolt\EventLoop::delay($cancelAfter, static fn () => $cancel->cancel());
            $started = hrtime(true);

            try {
                $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 64 * 1024 * 1024, 1024, [], $cancel->getCancellation())->await();
                $thrown = null;
            } catch (\Throwable $e) {
                $thrown = $e;
            }
            if ($timer !== null) {
                \Revolt\EventLoop::cancel($timer);
            }

            expect($thrown)->toBeInstanceOf($failure, $label)
                ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0, $label);

            // The socket was closed, not returned to the pool: a new scan connects anew (and, with the short
            // timeouts of the client, meets the same slow answer).
            try {
                $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 64 * 1024 * 1024, 1024)->await();
            } catch (IcapTimeoutException) {
            }
            expect($server->connections)->toBe(2, $label);
        } finally {
            $pool->close();
            $server->stop();
        }
    }
});

it('refuses what it cannot do before it opens a file or sends a byte', function () {
    $missing = sys_get_temp_dir() . '/icap-discard-missing-' . bin2hex(random_bytes(6));
    // A transport and a parser of their own: nothing here tells them how to receive a response without keeping it.
    $plain = new class () implements TransportInterface {
        public int $sent = 0;

        #[\Override]
        public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
        {
            $this->sent++;

            return Future::complete(IcapTestServer::CLEAN);
        }
    };
    $ownParser = new class () implements ResponseParserInterface {
        #[\Override]
        public function parse(string $rawResponse): IcapResponse
        {
            return new IcapResponse(204);
        }
    };
    $config = new Config('icap.example');

    $cases = [
        'a transport that cannot discard' => new IcapClient($config, $plain, new RequestFormatter(), new ResponseParser()),
        'a parser of its own'             => new IcapClient($config, new AsyncAmpTransport(), new RequestFormatter(), $ownParser),
    ];
    foreach ($cases as $label => $client) {
        // The file does not exist: had the client got as far as opening it, the error would be an IcapStorageException.
        expect(fn () => $client->scanFileDiscardingBody('/avscan', $missing, 1024))->toThrow(\LogicException::class, '', $label);
        expect(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $missing, 1024))->toThrow(\LogicException::class, '', $label);
    }
    expect($plain->sent)->toBe(0);

    // The budget is a figure of the call, and a mistake with it is told at once.
    $client = new IcapClient($config, new AsyncAmpTransport(), new RequestFormatter(), new ResponseParser());
    expect(fn () => $client->scanFileDiscardingBody('/avscan', $missing, 0))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $missing, -5))->toThrow(\InvalidArgumentException::class);

    // A session that cannot discard is found out before the preview is written to it.
    /** @var TransportSession&\Mockery\MockInterface $session */
    $session = Mockery::mock(TransportSession::class);
    /** @var \Mockery\Expectation $closes */
    $closes = $session->shouldReceive('close');
    $closes->once();
    $session->shouldNotReceive('write');
    /** @var SessionAwareTransport&DiscardingTransport&\Mockery\MockInterface $sessions */
    $sessions = Mockery::mock(SessionAwareTransport::class, DiscardingTransport::class);
    /** @var \Mockery\Expectation $opens */
    $opens = $sessions->shouldReceive('openSession');
    $opens->once()->andReturn($session);
    $client = new IcapClient($config, $sessions, new RequestFormatter(), new ResponseParser());
    $path = FileWorkers::file(str_repeat('a', 5000));

    expect(fn () => $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 1024, 100)->await())->toThrow(\LogicException::class);
});

it('offers the discarding scans through the wrappers of the client, and refuses them for a client that cannot', function () {
    $size = 300_000;
    $server = IcapTestServer::start(static fn (array $r) => IcapTestServer::echo($size));
    try {
        $client = discardClient($server);
        $path = FileWorkers::file(str_repeat('a', 5000));

        $wrappers = [
            'retrying, no preview' => fn () => (new RetryingIcapClient($client))->scanFileDiscardingBody('/avscan', $path, 1 << 20)->await(),
            'retrying, preview'    => fn () => (new RetryingIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await(),
            'synchronous, no preview' => fn () => (new SynchronousIcapClient($client))->scanFileDiscardingBody('/avscan', $path, 1 << 20),
            'synchronous, preview'    => fn () => (new SynchronousIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024),
        ];
        foreach ($wrappers as $label => $scan) {
            expect($scan()->getOriginalResponse()->discardedBodyBytes)->toBe($size, $label);
        }

        expect($client)->toBeInstanceOf(DiscardingIcapClient::class);
        /** @var IcapClientInterface&\Mockery\MockInterface $other */
        $other = Mockery::mock(IcapClientInterface::class);
        expect(fn () => (new RetryingIcapClient($other))->scanFileDiscardingBody('/avscan', $path, 1024))->toThrow(\LogicException::class)
            ->and(fn () => (new RetryingIcapClient($other))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1024))->toThrow(\LogicException::class)
            ->and(fn () => (new SynchronousIcapClient($other))->scanFileDiscardingBody('/avscan', $path, 1024))->toThrow(\LogicException::class)
            ->and(fn () => (new SynchronousIcapClient($other))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1024))->toThrow(\LogicException::class);
    } finally {
        $server->stop();
    }
});

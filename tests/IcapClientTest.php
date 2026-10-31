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

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\TransportSession;

uses(AsyncTestCase::class);

it('still lets a custom strategy decide a successful preview response', function () {
    $transport = new ScriptedTransport(["ICAP/1.0 204 No Content\r\n\r\n"]);
    $strategy = new class () implements PreviewStrategyInterface {
        public int $calls = 0;

        public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
        {
            $this->calls++;
            return PreviewDecision::ABORT_CLEAN;
        }
    };
    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser(), $strategy);
    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdefgh');

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, $strategy, $transport): void {
            expect($client->scanFileWithPreview('/svc', $tmp, 4)->await()->isInfected())->toBeFalse();
            expect($strategy->calls)->toBe(1);
            expect($transport->requests)->toHaveCount(1);
        });
    } finally {
        @unlink($tmp);
    }
});

/**
 * @return array{Config, RequestFormatterInterface&\Mockery\MockInterface, TransportInterface&\Mockery\MockInterface, ResponseParserInterface&\Mockery\MockInterface, PreviewStrategyInterface&\Mockery\MockInterface, IcapClient}
 */
function makeClient(): array
{
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var PreviewStrategyInterface&\Mockery\MockInterface $strategy */
    $strategy = m::mock(PreviewStrategyInterface::class);

    $client = new IcapClient($config, $transport, $formatter, $parser, $strategy);

    return [$config, $formatter, $transport, $parser, $strategy, $client];
}

it('OPTIONS: orchestrates formatter → transport → parser', function () {
    [$config, $formatter, $transport, $parser, , $client] = makeClient();

    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(fn (IcapRequest $req) => $req->method === 'OPTIONS' && str_contains($req->uri, '/service'));
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->withArgs(fn ($cfg, $chunks) => $cfg === $config && is_iterable($chunks));
    $trans->andReturn(\Amp\Future::complete('RESP'));

    $resp = new IcapResponse(200);
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->once();
    $p->with('RESP');
    $p->andReturn($resp);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $resp) {
        $res = $client->options('/service')->await();
        expect($res)->toBe($resp);
    });

    m::close();
});

it('scanFile wraps the file as an encapsulated HttpResponse with Content-Length', function () {
    [, $formatter, $transport, $parser, , $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload-bytes');

    $captured = null;
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return $req->method === 'RESPMOD' && $req->encapsulatedResponse !== null;
    });
    // A formatter that reads the file to its end, as a scan requires of one.
    $fmt->andReturnUsing(static fn (IcapRequest $request): array => iterator_to_array((new RequestFormatter())->format($request), false));

    /** @var \Mockery\Expectation $t2 */
    $t2 = $transport->shouldReceive('request');
    $t2->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p2 */
    $p2 = $parser->shouldReceive('parse');
    $p2->andReturn(new IcapResponse(204));

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
            $client->scanFile('/service', $tmp)->await();
            assert($captured instanceof IcapRequest);
            $encapsulated = $captured->encapsulatedResponse;
            assert($encapsulated !== null);
            // The body is the file the client opened itself, not a stream of the caller.
            expect($encapsulated->statusCode)->toBe(200)
                ->and($encapsulated->headers['Content-Length'])->toBe(['13'])
                ->and($encapsulated->body)->toBeObject();
        });
    } finally {
        @unlink($tmp);
    }
    m::close();
});

/**
 * A client over a transport with sessions, all mocks: the preview and the rest of the file go out through the session.
 *
 * @return array{RequestFormatterInterface&\Mockery\MockInterface, TransportSession&\Mockery\MockInterface, ResponseParserInterface&\Mockery\MockInterface, PreviewStrategyInterface&\Mockery\MockInterface, IcapClient}
 */
function makeSessionClient(): array
{
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var SessionAwareTransport&\Mockery\MockInterface $transport */
    $transport = m::mock(SessionAwareTransport::class);
    /** @var TransportSession&\Mockery\MockInterface $session */
    $session = m::mock(TransportSession::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var PreviewStrategyInterface&\Mockery\MockInterface $strategy */
    $strategy = m::mock(PreviewStrategyInterface::class);

    /** @var \Mockery\Expectation $opens */
    $opens = $transport->shouldReceive('openSession');
    $opens->once();
    $opens->andReturn($session);
    $transport->shouldNotReceive('request');

    return [$formatter, $session, $parser, $strategy, new IcapClient(new Config('icap.example'), $transport, $formatter, $parser, $strategy)];
}

it('scanFileWithPreview marks the preview complete (ieof) when file size <= preview size — no continuation', function () {
    [$formatter, $session, $parser, $strategy, $client] = makeSessionClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    $captured = null;
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return true;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $sw */
    $sw = $session->shouldReceive('write');
    $sw->once();
    /** @var \Mockery\Expectation $sr */
    $sr = $session->shouldReceive('readResponse');
    $sr->once();
    $sr->andReturn('RESP');
    /** @var \Mockery\Expectation $srel */
    $srel = $session->shouldReceive('release');
    $srel->once();
    $session->shouldNotReceive('close');
    /** @var \Mockery\Expectation $pp */
    $pp = $parser->shouldReceive('parse');
    $pp->andReturn(new IcapResponse(204));
    $strategy->shouldNotReceive('handlePreviewResponse');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        assert($captured instanceof IcapRequest);
        expect($captured->previewIsComplete)->toBeTrue()
            ->and($captured->headers['Preview'])->toBe(['3'])
            ->and($captured->headers['Allow'])->toBe(['204'])
            ->and($res->isInfected())->toBeFalse();
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview consults strategy and sends the remainder on the session on CONTINUE_SENDING', function () {
    [$formatter, $session, $parser, $strategy, $client] = makeSessionClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var IcapRequest[] $calls */
    $calls = [];
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    // The preview is formatted; the rest of the file is no request of its own and does not pass the formatter.
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$calls) {
        $calls[] = $req;
        return true;
    });
    $fmt->andReturnUsing(static fn (IcapRequest $request): array => iterator_to_array((new RequestFormatter())->format($request), false));

    // A session takes what it is given to its end, as a scan requires of one.
    $wire = [];
    /** @var \Mockery\Expectation $sw */
    $sw = $session->shouldReceive('write');
    $sw->twice();
    $sw->andReturnUsing(static function (iterable $chunks) use (&$wire): void {
        $wire[] = implode('', is_array($chunks) ? $chunks : iterator_to_array($chunks, false));
    });
    /** @var \Mockery\Expectation $sr */
    $sr = $session->shouldReceive('readResponse');
    $sr->twice();
    $sr->andReturn('RESP1', 'RESP2');
    /** @var \Mockery\Expectation $srel */
    $srel = $session->shouldReceive('release');
    $srel->once();
    $session->shouldNotReceive('close');

    $previewResp = new IcapResponse(100);
    $finalResp = new IcapResponse(200);
    /** @var \Mockery\Expectation $pr1 */
    $pr1 = $parser->shouldReceive('parse');
    $pr1->once();
    $pr1->with('RESP1');
    $pr1->andReturn($previewResp);
    /** @var \Mockery\Expectation $pr2 */
    $pr2 = $parser->shouldReceive('parse');
    $pr2->once();
    $pr2->with('RESP2');
    $pr2->andReturn($finalResp);

    /** @var \Mockery\Expectation $s */
    $s = $strategy->shouldReceive('handlePreviewResponse');
    $s->once();
    $s->with($previewResp);
    $s->andReturn(PreviewDecision::CONTINUE_SENDING);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $finalResp, &$calls, &$wire) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        expect($res->getOriginalResponse())->toBe($finalResp)
            ->and($calls[0]->previewIsComplete)->toBeFalse()
            ->and($calls[0]->headers['Preview'])->toBe(['3'])
            ->and($calls[0]->headers['Allow'])->toBe(['204'])
            // Only the chunks of the rest of the body, with their terminator: no second head.
            ->and($wire[1])->toBe("3\r\ndef\r\n0\r\n\r\n");
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview stops on ABORT_INFECTED without sending the remainder', function () {
    [$formatter, $session, $parser, $strategy, $client] = makeSessionClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $sw */
    $sw = $session->shouldReceive('write');
    $sw->once();
    /** @var \Mockery\Expectation $sr */
    $sr = $session->shouldReceive('readResponse');
    $sr->once();
    $sr->andReturn('RESP1');
    /** @var \Mockery\Expectation $srel */
    $srel = $session->shouldReceive('release');
    $srel->once();
    $previewResp = new IcapResponse(200);
    /** @var \Mockery\Expectation $pp */
    $pp = $parser->shouldReceive('parse');
    $pp->andReturn($previewResp);
    /** @var \Mockery\Expectation $s */
    $s = $strategy->shouldReceive('handlePreviewResponse');
    $s->once();
    $s->andReturn(PreviewDecision::ABORT_INFECTED);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $previewResp) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        expect($res->getOriginalResponse())->toBe($previewResp);
    });

    unlink($tmp);
    m::close();
});

it('propagates exceptions from the formatter', function () {
    [, $formatter, $transport, , , $client] = makeClient();

    /** @var \Mockery\Expectation $ff */
    $ff = $formatter->shouldReceive('format');
    $ff->andThrow(new RuntimeException('fail'));
    $transport->shouldNotReceive('request');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(RuntimeException::class);
    });

    m::close();
});

/**
 * M08 — scanFile() and scanFileWithPreview() open their file inside the
 * operation and close it when the operation ends, whatever the outcome and
 * however long the failed Future and its trace arguments are kept: those
 * references used to be what held the file descriptor. That includes a size
 * lookup that throws: its exception carries the handle in its trace.
 */
it('closes the file it opened on every outcome, with the failed futures still held', function () {
    $throwing = new class () implements RequestFormatterInterface {
        #[\Override]
        public function format(IcapRequest $request): iterable
        {
            throw new RuntimeException('formatter failed');
        }
    };
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    $cases = [
        // label => [formatter, transport, size lookup throws, scan method]
        'success'                     => [new RequestFormatter(), new ScriptedTransport([$clean]), false, 'scanFile'],
        'formatter error'             => [$throwing, new ScriptedTransport([$clean]), false, 'scanFile'],
        'transport error'             => [new RequestFormatter(), new ScriptedTransport(['fail']), false, 'scanFile'],
        'parser error'                => [new RequestFormatter(), new ScriptedTransport(['not an ICAP response']), false, 'scanFile'],
        'size lookup throws'          => [new RequestFormatter(), new ScriptedTransport([$clean]), true, 'scanFile'],
        'size lookup throws, preview' => [new RequestFormatter(), new ScriptedTransport([$clean]), true, 'scanFileWithPreview'],
    ];

    $keepArgs = ini_set('zend.exception_ignore_args', '0');
    $held = [];

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($cases, &$held) {
            $opened = 0;

            foreach ($cases as $case => [$formatter, $transport, $statThrows, $method]) {
                $client = new IcapClient(new Config('icap.example'), $transport, $formatter, new ResponseParser());

                $path = ScriptedSource::add('held', 'payload', statThrows: $statThrows);
                $future = $method === 'scanFile'
                    ? $client->scanFile('/svc', $path)
                    : $client->scanFileWithPreview('/svc', $path);
                $held[] = $future;
                try {
                    $future->await();
                } catch (Throwable $e) {
                    $held[] = $e;
                }
                $opened++;

                // The handle itself is closed, whatever the failed future and its exception still refer to.
                expect(ScriptedSource::$opened)->toBe($opened, $case);
                expect(ScriptedSource::$closed)->toBe($opened, $case);
            }
        });
    } finally {
        ScriptedSource::reset();
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
});

/**
 * The size is the one the open file reports, and a size that cannot be
 * established is an error, never an empty file.
 */
it('takes the size from the open file and refuses one without a size', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    try {
        $transport = new ScriptedTransport([$clean]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client) {
            $client->scanFile('/svc', ScriptedSource::add('sized', 'abcde', size: 5))->await();
        });
        expect($transport->requests[0])->toContain("Content-Length: 5\r\n");

        $transport = new ScriptedTransport([$clean]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());
        $this->runAsyncTest(function () use ($client) {
            $path = ScriptedSource::add('blind', 'abcde', statFails: true);
            expect(fn () => $client->scanFile('/svc', $path)->await())->toThrow(IcapStorageException::class);
        });
        // Nothing was sent as an empty file, and the handle was given back.
        expect($transport->requests)->toBe([]);
        expect(ScriptedSource::$closed)->toBe(ScriptedSource::$opened);
    } finally {
        ScriptedSource::reset();
    }
});

/**
 * A file that cannot be opened fails the Future, not the call, with an exception of the taxonomy; nothing is sent.
 */
it('fails the Future with an IcapStorageException for a file that cannot be opened', function () {
    $missing = sys_get_temp_dir() . '/icap-flow-missing-' . bin2hex(random_bytes(6));
    $transport = new ScriptedTransport();
    $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());

    // fopen() warns; the client silences it and reports the failure, and the runner need not see it either.
    set_error_handler(static fn (): bool => true);

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $missing) {
            $scans = [
                'scanFile'            => fn () => $client->scanFile('/svc', $missing),
                'scanFileWithPreview' => fn () => $client->scanFileWithPreview('/svc', $missing, 4),
            ];

            foreach ($scans as $scan => $start) {
                $future = $start();

                expect(fn () => $future->await())->toThrow(IcapStorageException::class, 'Unable to open file', $scan);
            }
        });
    } finally {
        restore_error_handler();
    }

    expect($transport->requests)->toBe([]);
});

/**
 * A preview announces a complete body (`ieof`) or goes on with the rest only from
 * a source whose end was actually observed. A source that fails, stands still or
 * has another size than its handle reported is an error before anything is sent.
 * The wire of the continuation has its test in PreviewContinueStrictTest.
 */
it('sends a preview only from a source that delivers what its handle announced', function () {
    $cont = "ICAP/1.0 100 Continue\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    $cases = [
        // label => [source, preview size, error expected, replies, writes that went out]
        'control: preview, continue, rest of the body' => [fn () => ScriptedSource::add('p', 'abcdefgh'), 4, false, [$cont, $clean], 2],
        'read error inside the preview'                => [fn () => ScriptedSource::add('p', 'abcdefgh', failAfter: 2), 4, true, [$cont, $clean], 0],
        'no progress inside the preview'               => [fn () => ScriptedSource::add('p', 'abcdefgh', stallAfter: 2), 4, true, [$cont, $clean], 0],
        'no progress at the end it claims'             => [fn () => ScriptedSource::add('p', 'abcd', stallAfter: 4), 4, true, [$clean], 0],
        'more bytes than the handle reported'          => [fn () => ScriptedSource::add('p', 'abcdefgh', size: 4), 4, true, [$clean], 0],
        'fewer bytes than the handle reported'         => [fn () => ScriptedSource::add('p', 'abc', size: 8), 4, true, [$cont, $clean], 0],
    ];

    foreach ($cases as $case => [$source, $previewSize, $fails, $replies, $writes]) {
        $transport = new ScriptedTransport($replies);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());

        try {
            $path = $source();
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $path, $previewSize, $fails, $case) {
                if ($fails) {
                    expect(fn () => $client->scanFileWithPreview('/svc', $path, $previewSize)->await())
                        ->toThrow(IcapStorageException::class, '', $case);
                } else {
                    expect($client->scanFileWithPreview('/svc', $path, $previewSize)->await()->isInfected())
                        ->toBeFalse($case);
                }
            });

            expect(count($transport->requests))->toBe($writes, $case);
            if (!$fails) {
                // The rest of the body, after the 4 bytes of the preview: no second head.
                expect($transport->requests[1])->toBe("4\r\nefgh\r\n0\r\n\r\n");
            }
            // The client's own handle is given back in every case.
            expect(ScriptedSource::$closed)->toBe(ScriptedSource::$opened, $case);
        } finally {
            ScriptedSource::reset();
        }
    }
});

/**
 * The whole body of scanFile(), and the rest of the body after a preview, goes out with the length its envelope
 * announced. A file that has grown or shrunk since its handle was measured
 * fails before the final chunk, sends nothing past the announced length and
 * never ends in a result. (A file of the same size with other content stays
 * open until the source is bound to the request.)
 */
it('sends the whole file only with the length it announced', function () {
    $cont = "ICAP/1.0 100 Continue\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    $cases = [
        // label => [scan, file content, replies, the last write ends with; null: the scan succeeds, what it ends with then]
        'scanFile, as announced'                => ['scanFile', 'abcdefgh', [$clean], null, "8\r\nabcdefgh\r\n0\r\n\r\n"],
        'scanFile, shorter'                     => ['scanFile', 'abc', [$clean], "3\r\nabc\r\n", ''],
        'scanFile, longer'                      => ['scanFile', 'abcdefghijk', [$clean], "8\r\nabcdefgh\r\n", ''],
        'rest after a preview, as announced'    => ['preview', 'abcdefgh', [$cont, $clean], null, "4\r\nefgh\r\n0\r\n\r\n"],
        'rest after a preview, shorter'         => ['preview', 'abcdef', [$cont, $clean], "2\r\nef\r\n", ''],
        'rest after a preview, longer'          => ['preview', 'abcdefghijk', [$cont, $clean], "4\r\nefgh\r\n", ''],
    ];

    foreach ($cases as $case => [$scan, $content, $replies, $tail, $whole]) {
        $transport = new ScriptedTransport($replies);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());

        try {
            // The handle reports 8 bytes; the file delivers what the case says.
            $path = ScriptedSource::add('whole', $content, size: 8);
            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $scan, $path, $tail, $case) {
                $run = $scan === 'scanFile'
                    ? fn () => $client->scanFile('/svc', $path)->await()
                    : fn () => $client->scanFileWithPreview('/svc', $path, 4)->await();

                if ($tail === null) {
                    expect($run()->isInfected())->toBeFalse($case);
                } else {
                    expect($run)->toThrow(IcapStorageException::class, '', $case);
                }
            });

            $last = $transport->requests[count($transport->requests) - 1];
            expect(str_ends_with($last, $tail ?? $whole))->toBeTrue($case);
            expect(ScriptedSource::$closed)->toBe(ScriptedSource::$opened, $case);
        } finally {
            ScriptedSource::reset();
        }
    }
});

/**
 * Ownership — a stream the caller hands in through request() is only read.
 * Closing it is the caller's call, after success and after failure alike.
 */
it('leaves a stream the caller passed in open', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    foreach (['clean answer' => $clean, 'failing transport' => 'fail'] as $case => $reply) {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open php://memory.');
        }

        try {
            fwrite($stream, 'payload');
            rewind($stream);
            $client = new IcapClient(
                new Config('icap.example'),
                new ScriptedTransport([$reply]),
                new RequestFormatter(),
                new ResponseParser(),
            );
            $request = new IcapRequest(
                method: 'RESPMOD',
                uri: 'icap://icap.example/svc',
                encapsulatedResponse: new HttpResponse(200, body: $stream),
            );

            /** @var AsyncTestCase $this */
            $this->runAsyncTest(function () use ($client, $request) {
                try {
                    $client->request($request)->await();
                } catch (IcapConnectionException) {
                    // the failing transport; the stream is what counts here
                }
            });

            expect(is_resource($stream))->toBeTrue($case);
        } finally {
            fclose($stream);
        }
    }
});

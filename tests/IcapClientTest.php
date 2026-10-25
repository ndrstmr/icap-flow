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
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

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
    $bodyWasOpen = false;
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$captured, &$bodyWasOpen) {
        $captured = $req;
        $bodyWasOpen = is_resource($req->encapsulatedResponse?->body);
        return $req->method === 'RESPMOD' && $req->encapsulatedResponse !== null;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $t2 */
    $t2 = $transport->shouldReceive('request');
    $t2->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p2 */
    $p2 = $parser->shouldReceive('parse');
    $p2->andReturn(new IcapResponse(204));

    try {
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $tmp, &$captured, &$bodyWasOpen) {
            $client->scanFile('/service', $tmp)->await();
            assert($captured instanceof IcapRequest);
            $encapsulated = $captured->encapsulatedResponse;
            assert($encapsulated !== null);
            // The handle is the client's own: open while the request is built,
            // closed once the scan has ended.
            expect($encapsulated->statusCode)->toBe(200)
                ->and($encapsulated->headers['Content-Length'])->toBe(['13'])
                ->and($bodyWasOpen)->toBeTrue()
                ->and(is_resource($encapsulated->body))->toBeFalse();
        });
    } finally {
        @unlink($tmp);
    }
    m::close();
});

it('scanFileWithPreview marks the preview complete (ieof) when file size <= preview size — no continuation request', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

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

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->andReturn(\Amp\Future::complete('RESP'));
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

it('scanFileWithPreview consults strategy and sends remainder on CONTINUE_SENDING', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var IcapRequest[] $calls */
    $calls = [];
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->twice();
    $fmt->withArgs(function (IcapRequest $req) use (&$calls) {
        $calls[] = $req;
        return true;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->twice();
    $trans->andReturn(
        \Amp\Future::complete('RESP1'),
        \Amp\Future::complete('RESP2'),
    );
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
    $this->runAsyncTest(function () use ($client, $tmp, $finalResp, &$calls) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        expect($res->getOriginalResponse())->toBe($finalResp)
            ->and($calls[0]->previewIsComplete)->toBeFalse()
            ->and($calls[0]->headers['Preview'])->toBe(['3'])
            ->and($calls[0]->headers['Allow'])->toBe(['204'])
            ->and(isset($calls[1]->headers['Preview']))->toBeFalse();
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview stops on ABORT_INFECTED without sending the remainder', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->andReturn(\Amp\Future::complete('RESP1'));
    $previewResp = new IcapResponse(100);
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
            $baseline = count(get_resources('stream'));
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

                // The handle itself, not just its resource, is gone.
                expect(ScriptedSource::$opened)->toBe($opened, $case);
                expect(ScriptedSource::$closed)->toBe($opened, $case);
            }

            expect(count(get_resources('stream')))->toBe($baseline);
        });
    } finally {
        ScriptedSource::reset();
        if ($keepArgs !== false) {
            ini_set('zend.exception_ignore_args', $keepArgs);
        }
    }
});

/**
 * M07 — the size comes from the handle that is read, not from a second lookup
 * of the path, and a size that cannot be established is an error, never an
 * empty file.
 */
it('takes the size from the open handle and refuses a handle without metadata', function () {
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    try {
        $transport = new ScriptedTransport([$clean]);
        $client = new IcapClient(new Config('icap.example'), $transport, new RequestFormatter(), new ResponseParser());
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client) {
            // The handle reports 5 bytes; a path lookup would say 99.
            $client->scanFile('/svc', ScriptedSource::add('sized', 'abcde', size: 5, pathSize: 99))->await();
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
 * B08, M07 — a preview announces a complete body (`ieof`) or goes on with the
 * rest only from a source whose end was actually observed. A source that
 * fails, stands still or has another size than its handle reported is an
 * error before anything is sent, and a stream that cannot go back for the
 * full body must not send the rest of it as if it were the whole. This is the
 * two-request flow; the strict flow has its wire test in
 * PreviewContinueStrictTest.
 */
it('sends a preview only from a source that delivers what its handle announced', function () {
    $cont = "ICAP/1.0 100 Continue\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    // A formatter of its own that never reads the body: it must not be what
    // rewinds the stream, the client has to.
    $blind = new class () implements RequestFormatterInterface {
        #[\Override]
        public function format(IcapRequest $request): iterable
        {
            return ['request'];
        }
    };

    $cases = [
        // label => [source, preview size, error expected, replies, requests that went out, formatter]
        'control: preview, continue, full body'       => [fn () => ScriptedSource::add('p', 'abcdefgh'), 4, false, [$cont, $clean], 2, null],
        'read error inside the preview'               => [fn () => ScriptedSource::add('p', 'abcdefgh', failAfter: 2), 4, true, [$cont, $clean], 0, null],
        'no progress inside the preview'              => [fn () => ScriptedSource::add('p', 'abcdefgh', stallAfter: 2), 4, true, [$cont, $clean], 0, null],
        'no progress at the end it claims'            => [fn () => ScriptedSource::add('p', 'abcd', stallAfter: 4), 4, true, [$clean], 0, null],
        'more bytes than the handle reported'         => [fn () => ScriptedSource::add('p', 'abcdefgh', size: 4), 4, true, [$clean], 0, null],
        'fewer bytes than the handle reported'        => [fn () => ScriptedSource::add('p', 'abc', size: 8), 4, true, [$cont, $clean], 0, null],
        'stream that cannot rewind for the full body' => [fn () => ScriptedSource::add('p', 'abcdefgh', seekable: false), 4, true, [$cont, $clean], 1, $blind],
    ];

    foreach ($cases as $case => [$source, $previewSize, $fails, $replies, $requests, $formatter]) {
        $transport = new ScriptedTransport($replies);
        $client = new IcapClient(new Config('icap.example'), $transport, $formatter ?? new RequestFormatter(), new ResponseParser());

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

            expect(count($transport->requests))->toBe($requests, $case);
            if (!$fails) {
                expect($transport->requests[1])->toEndWith("8\r\nabcdefgh\r\n0\r\n\r\n");
            }
            // The client's own handle is given back in every case.
            expect(ScriptedSource::$closed)->toBe(ScriptedSource::$opened, $case);
        } finally {
            ScriptedSource::reset();
        }
    }
});

/**
 * M07 — the whole body of scanFile(), and of the full request after a preview
 * on a transport without sessions, goes out with the length its envelope
 * announced. A file that has grown or shrunk since its handle was measured
 * fails before the final chunk, sends nothing past the announced length and
 * never ends in a result. (A file of the same size with other content stays
 * open until the source is bound to the request.)
 */
it('sends the whole file only with the length it announced', function () {
    $cont = "ICAP/1.0 100 Continue\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
    $whole = "8\r\nabcdefgh\r\n0\r\n\r\n";

    $cases = [
        // label => [scan, file content, replies, last request ends with; null: the scan succeeds]
        'scanFile, as announced'                    => ['scanFile', 'abcdefgh', [$clean], null],
        'scanFile, shorter'                         => ['scanFile', 'abc', [$clean], "3\r\nabc\r\n"],
        'scanFile, longer'                          => ['scanFile', 'abcdefghijk', [$clean], "8\r\nabcdefgh\r\n"],
        'full request after a preview, as announced' => ['preview', 'abcdefgh', [$cont, $clean], null],
        'full request after a preview, shorter'      => ['preview', 'abcdef', [$cont, $clean], "6\r\nabcdef\r\n"],
        'full request after a preview, longer'       => ['preview', 'abcdefghijk', [$cont, $clean], "8\r\nabcdefgh\r\n"],
    ];

    foreach ($cases as $case => [$scan, $content, $replies, $tail]) {
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

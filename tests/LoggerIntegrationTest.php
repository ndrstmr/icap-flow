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

use Amp\Cancellation;
use Amp\Future;
use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(AsyncTestCase::class);

// The scripted sources of a test, and the counters of the stream wrapper, are not left behind by one that fails.
afterEach(fn () => ScriptedSource::reset());

/**
 * M3.1 — PSR-3 logger observability.
 *
 * The client must emit structured events at request start and at
 * request completion (with status code). Deployments in the public
 * sector rely on this for BSI-Grundschutz-compatible auditing.
 */

it('logs a request-started event with the ICAP method and no URI', function () {
    /** @var LoggerInterface&\Mockery\MockInterface $logger */
    $logger = m::mock(LoggerInterface::class);

    /** @var \Mockery\Expectation $start */
    $start = $logger->shouldReceive('info');
    $start->once();
    $start->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'ICAP request')
            && ($ctx['method'] ?? null) === 'OPTIONS'
            && !array_key_exists('uri', $ctx);
    });

    /** @var \Mockery\Expectation $done */
    $done = $logger->shouldReceive('info');
    $done->once();
    $done->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'completed')
            && ($ctx['statusCode'] ?? null) === 200;
    });

    [$client] = buildClientWithLogger($logger, new IcapResponse(200));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/svc')->await();
    });

    m::close();
    expect(true)->toBeTrue(); // Mockery verified; satisfy PHPUnit strict-mode
});

it('logs a warning when the request raises an exception', function () {
    /** @var LoggerInterface&\Mockery\MockInterface $logger */
    $logger = m::mock(LoggerInterface::class);

    // Start event always fires.
    /** @var \Mockery\Expectation $start */
    $start = $logger->shouldReceive('info');
    $start->once();
    // Warning carries the status code when available.
    /** @var \Mockery\Expectation $warn */
    $warn = $logger->shouldReceive('warning');
    $warn->once();
    $warn->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'failed')
            && ($ctx['statusCode'] ?? null) === 503
            && ($ctx['exception_class'] ?? null) === \Ndrstmr\Icap\Exception\IcapServerException::class;
    });

    [$client] = buildClientWithLogger($logger, new IcapResponse(503));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(\Ndrstmr\Icap\Exception\IcapServerException::class);
    });

    m::close();
});

it('does not leak response headers into log context', function () {
    /** @var LoggerInterface&\Mockery\MockInterface $logger */
    $logger = m::mock(LoggerInterface::class);

    $logContexts = [];
    /** @var \Mockery\Expectation $info */
    $info = $logger->shouldReceive('info');
    $info->withArgs(function (string $msg, array $ctx) use (&$logContexts) {
        $logContexts[] = $ctx;
        return true;
    });

    // Response carries a virus header — this must NOT appear in log context.
    [$client] = buildClientWithLogger($logger, new IcapResponse(200, [
        'X-Virus-Name' => ['Eicar-Test-Signature'],
        'X-Infection-Found' => ['Type=0; Resolution=2; Threat=Eicar'],
    ]));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/svc')->await();
    });

    // Verify that no log context contains response header values.
    foreach ($logContexts as $ctx) {
        // Keys that are expected: method, port, statusCode, infected, exception_class.
        // Keys that must NOT appear: headers, X-Virus-Name, or any raw header data.
        expect($ctx)->not->toHaveKey('headers');
        expect($ctx)->not->toHaveKey('X-Virus-Name');
        expect($ctx)->not->toHaveKey('X-Infection-Found');
        $serialized = json_encode($ctx);
        expect($serialized)->not->toContain('Eicar');
    }

    m::close();
});

/**
 * @return array{IcapClient}
 */
function buildClientWithLogger(LoggerInterface $logger, IcapResponse $canned): array
{
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn($canned);

    return [new IcapClient($config, $transport, $formatter, $parser, null, $logger)];
}

/*
 * M16: the log is read by people and systems that were never meant to see
 * what a caller, a server or an exception carries: URIs with queries, hosts
 * and service paths, status and header lines, bodies, exception messages.
 * The events stay, with fixed messages and a short list of metadata.
 */
const LOG_SECRETS = [
    'host'      => 'secret-host-5c1e.internal',
    'service'   => 'SECRET-SERVICE-5c1e',
    'uri'       => 'SECRET-URI-5c1e',
    'status'    => 'SECRET-STATUS-5c1e',
    'header'    => 'SECRET-HEADER-5c1e',
    'body'      => 'SECRET-BODY-5c1e',
    'exception' => 'SECRET-EXCEPTION-5c1e',
    'method'    => 'SECRETMETHOD5c1e',
    'virus'     => 'SECRET-VIRUS-5c1e',
];

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => is_string($level) ? $level : '?',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

/** A transport whose request fails with the given error. */
function failingLogTransport(Throwable $error): TransportInterface
{
    return new class ($error) implements TransportInterface {
        public function __construct(private readonly Throwable $error)
        {
        }

        #[\Override]
        public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
        {
            return Future::error($this->error);
        }
    };
}

/**
 * The events the logger got, then the rule for every record: a known message,
 * a context of allowed keys with plain values, and none of the secrets
 * anywhere in what was logged.
 *
 * @param list<string> $events expected, as "level: message"
 */
function expectOnlySafeLogs(RecordingLogger $logger, array $events, string $case): void
{
    expect(array_map(static fn (array $record): string => $record['level'] . ': ' . $record['message'], $logger->records))
        ->toBe($events, $case);

    $allowed = ['method', 'port', 'statusCode', 'infected', 'exception_class'];
    foreach ($logger->records as $record) {
        foreach ($record['context'] as $key => $value) {
            expect(in_array($key, $allowed, true))->toBe(true, "$case: context key $key");
            expect($value === null || is_scalar($value))->toBe(true, "$case: context value of $key");
        }
    }

    // print_r() shows what json_encode() would hide: the properties of an object.
    $text = print_r($logger->records, true);
    foreach (LOG_SECRETS as $channel => $secret) {
        expect(str_contains($text, $secret))->toBe(false, "$case: $channel leaked into the log");
    }
}

it('logs the events of request() without URI, host, server text or exception message', function () {
    $s = LOG_SECRETS;
    $uri = "icap://{$s['host']}/svc-{$s['service']}?user={$s['uri']}";
    $respmod = new IcapRequest('RESPMOD', $uri, ['X-Note' => $s['header']], null, new HttpResponse(200, 'OK', [], $s['body']));
    $clean = "ICAP/1.0 204 No Content\r\nX-Note: {$s['header']}\r\nEncapsulated: null-body=0\r\n\r\n";
    $started = 'info: ICAP request started';
    $completed = 'info: ICAP request completed';
    $failed = 'warning: ICAP request failed';
    // A body that stops being readable after four bytes: the caller's stream, with the secret in its content.
    $failing = fopen(ScriptedSource::add('log-source', $s['body'], failAfter: 4), 'rb');
    if ($failing === false) {
        throw new RuntimeException('Cannot open the scripted source.');
    }

    // label => [request, transport, exception thrown, events, context of the last event]
    $cases = [
        'clean' => [
            $respmod, new ScriptedTransport([$clean]), null,
            [$started, $completed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => 204, 'infected' => false],
        ],
        'infected' => [
            $respmod,
            new ScriptedTransport(["ICAP/1.0 200 OK\r\nX-Virus-Name: {$s['virus']}\r\nEncapsulated: null-body=0\r\n\r\n"]),
            null,
            [$started, $completed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => 200, 'infected' => true],
        ],
        'server error' => [
            $respmod, new ScriptedTransport(["ICAP/1.0 503 Service Unavailable {$s['status']}\r\n\r\n"]), IcapServerException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => 503, 'exception_class' => IcapServerException::class],
        ],
        'transport error' => [
            $respmod, failingLogTransport(new IcapConnectionException("connect to {$s['host']} failed: {$s['exception']}")), IcapConnectionException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => IcapConnectionException::class],
        ],
        'anonymous exception class' => [
            $respmod, failingLogTransport(new class ($s['exception']) extends RuntimeException {
            }), RuntimeException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => 'RuntimeException@anonymous'],
        ],
        'invalid status line' => [
            $respmod, new ScriptedTransport(["HTTP/1.1 200 {$s['status']}\r\n\r\n"]), IcapMalformedResponseException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => IcapMalformedResponseException::class],
        ],
        'invalid header line' => [
            $respmod, new ScriptedTransport(["ICAP/1.0 200 OK\r\n{$s['header']} without a colon\r\n\r\n"]), IcapMalformedResponseException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => IcapMalformedResponseException::class],
        ],
        'source that fails while the body goes out' => [
            new IcapRequest('RESPMOD', $uri, [], null, new HttpResponse(200, 'OK', [], $failing)), new ScriptedTransport([$clean]), IcapStorageException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => IcapStorageException::class],
        ],
        'request the formatter refuses' => [
            new IcapRequest('RESPMOD', $uri, ["Bad Name {$s['header']}" => 'x']), new ScriptedTransport([$clean]), InvalidArgumentException::class,
            [$started, $failed], ['method' => 'RESPMOD', 'port' => 1344, 'statusCode' => null, 'exception_class' => InvalidArgumentException::class],
        ],
        'extension method' => [
            new IcapRequest($s['method'], $uri), new ScriptedTransport([$clean]), null,
            [$started, $completed], ['method' => 'other', 'port' => 1344, 'statusCode' => 204, 'infected' => false],
        ],
    ];

    foreach ($cases as $case => [$request, $transport, $exception, $events, $context]) {
        $logger = new RecordingLogger();
        $client = new IcapClient(new Config($s['host']), $transport, new RequestFormatter(), new ResponseParser(), logger: $logger);

        $thrown = null;
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $request, &$thrown) {
            try {
                $client->request($request)->await();
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        // The errors still reach the caller; only the log is kept short.
        expect($exception === null ? $thrown === null : $thrown instanceof $exception)->toBe(true, $case);
        expectOnlySafeLogs($logger, $events, $case);
        expect(end($logger->records)['context'] ?? null)->toEqual($context, $case);
    }
});

it('logs the events of options() without URI, host, service or exception message', function () {
    $s = LOG_SECRETS;
    $service = "/svc-{$s['service']}?token={$s['uri']}";
    $started = 'info: ICAP request started';
    $completed = 'info: ICAP request completed';
    $failed = 'warning: ICAP request failed';
    $port = 1344;

    // label => [transport, exception thrown, events, context of the last event]
    $cases = [
        'clean' => [
            new ScriptedTransport(["ICAP/1.0 200 OK\r\nX-Note: {$s['header']}\r\nEncapsulated: null-body=0\r\n\r\n"]), null,
            [$started, $completed], ['method' => 'OPTIONS', 'port' => $port, 'statusCode' => 200],
        ],
        'server error' => [
            new ScriptedTransport(["ICAP/1.0 503 Service Unavailable {$s['status']}\r\n\r\n"]), IcapServerException::class,
            [$started, $failed], ['method' => 'OPTIONS', 'port' => $port, 'statusCode' => 503, 'exception_class' => IcapServerException::class],
        ],
        'transport error' => [
            failingLogTransport(new IcapConnectionException("connect to {$s['host']}{$service} failed: {$s['exception']}")), IcapConnectionException::class,
            [$started, $failed], ['method' => 'OPTIONS', 'port' => $port, 'statusCode' => null, 'exception_class' => IcapConnectionException::class],
        ],
        'invalid status line' => [
            new ScriptedTransport(["HTTP/1.1 200 {$s['status']}\r\n\r\n"]), IcapMalformedResponseException::class,
            [$started, $failed], ['method' => 'OPTIONS', 'port' => $port, 'statusCode' => null, 'exception_class' => IcapMalformedResponseException::class],
        ],
        'invalid header line' => [
            new ScriptedTransport(["ICAP/1.0 200 OK\r\n{$s['header']} without a colon\r\n\r\n"]), IcapMalformedResponseException::class,
            [$started, $failed], ['method' => 'OPTIONS', 'port' => $port, 'statusCode' => null, 'exception_class' => IcapMalformedResponseException::class],
        ],
    ];

    foreach ($cases as $case => [$transport, $exception, $events, $context]) {
        $logger = new RecordingLogger();
        $client = new IcapClient(new Config($s['host']), $transport, new RequestFormatter(), new ResponseParser(), logger: $logger);

        $thrown = null;
        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $service, &$thrown) {
            try {
                $client->options($service)->await();
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        expect($exception === null ? $thrown === null : $thrown instanceof $exception)->toBe(true, $case);
        expectOnlySafeLogs($logger, $events, $case);
        expect(end($logger->records)['context'] ?? null)->toEqual($context, $case);
    }
});

it('hands the exception to the caller with its own message', function () {
    $s = LOG_SECRETS;
    $client = new IcapClient(
        new Config($s['host']),
        failingLogTransport(new IcapConnectionException("connect failed: {$s['exception']}")),
        new RequestFormatter(),
        new ResponseParser(),
        logger: new RecordingLogger(),
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $s) {
        expect(fn () => $client->options('/svc')->await())
            ->toThrow(IcapConnectionException::class, $s['exception']);
    });
});

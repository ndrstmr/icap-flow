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

use Ndrstmr\Icap\CicapVerdict;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DefaultPreviewStrategy;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * AP-16 (M01): the verified mode through the whole client, against a local server that answers with the recorded
 * answers of the reference service. The Config is the default one: the header list is not widened, because the
 * verdict of this mode does not depend on it. The live counterpart is tests/Integration/CicapVirusScanTest.php.
 */

afterEach(function (): void {
    FileWorkers::removeFiles();
});

function cicapAnswerBytes(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/Fixtures/cicap-virus-scan/' . $name . '.icap');
}

function cicapClient(IcapTestServer $server, bool $sessions, bool $verified = true, string $service = '/avscan'): IcapClient
{
    return new IcapClient(
        new Config('127.0.0.1', $server->port, streamTimeout: 10.0, operationTimeout: 30.0),
        $sessions ? new AsyncAmpTransport() : new OneRequestTransport(),
        new RequestFormatter(),
        new ResponseParser(),
        verdict: $verified ? new CicapVerdict($service) : null,
    );
}

/** The answer to a preview that asks for the rest, with the fields the test puts in it (c-icap sends none). */
function cicapContinue(string $fields = ''): string
{
    return "ICAP/1.0 100 Continue\r\n{$fields}\r\n";
}

/**
 * A RESPMOD request of its own for request(): the message announces $announced bytes, $body is what goes out.
 *
 * @param array<string, string> $headers
 */
function cicapRawRequest(string $uri, array $headers, string $body, int $announced, bool $previewIsComplete = false): IcapRequest
{
    return new IcapRequest(
        'RESPMOD',
        $uri,
        $headers,
        encapsulatedResponse: new HttpResponse(statusCode: 200, headers: ['Content-Length' => [(string) $announced]], body: $body),
        previewIsComplete: $previewIsComplete,
    );
}

/**
 * Whether it scans without a preview, or with one (the file is 5000 bytes, the preview 1024), through the buffering
 * or the discarding helper.
 *
 * @return \Closure(IcapClient, string): ScanResult
 */
function cicapScan(bool $preview, bool $discarding): \Closure
{
    return static fn (IcapClient $client, string $path): ScanResult => $preview
        ? ($discarding
            ? $client->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await()
            : $client->scanFileWithPreview('/avscan', $path, 1024)->await())
        : ($discarding
            ? $client->scanFileDiscardingBody('/avscan', $path, 1 << 20)->await()
            : $client->scanFile('/avscan', $path)->await());
}

/** The threat that the recorded answer reports. */
function cicapThreatOf(string $answer): string
{
    return match ($answer) {
        'infected-200' => 'Eicar-Test-Signature',
        default => throw new \LogicException("{$answer} reports no threat."),
    };
}

it('scans without a preview and offers 204, because only a 204 shows that the file was scanned', function () {
    // [what the service answers, the result or the exception]
    $cases = [
        '204' => ['clean-204', false, null],
        'EICAR' => ['infected-200', true, 'Eicar-Test-Signature'],
        // The engine did not look into the file (AlertExceedsMax): neither infected nor clean.
        'a limit of the engine' => ['limit-exceeded-200', IcapProtocolException::class, null],
        'an encrypted archive' => ['encrypted-200', IcapProtocolException::class, null],
        'the message back, as for a type it does not scan' => ['not-scanned-200', IcapProtocolException::class, null],
        'a failing scanner' => ['error-500', IcapServerException::class, null],
        '100 Continue, which is no answer without a preview' => ['continue-100', IcapProtocolException::class, null],
    ];

    foreach ([false, true] as $discarding) {
        foreach ($cases as $label => [$answer, $expected, $name]) {
            $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes($answer)]);
            try {
                $path = FileWorkers::file(str_repeat('a', 5000));
                $scan = cicapScan(false, $discarding);
                $how = ($discarding ? 'discarding: ' : 'buffering: ') . $label;

                if (is_string($expected)) {
                    expect(fn () => $scan(cicapClient($server, true), $path))->toThrow($expected, '', $how);
                } else {
                    $result = $scan(cicapClient($server, true), $path);
                    expect($result->isInfected())->toBe($expected, $how)
                        ->and($result->getVirusName())->toBe($name, $how)
                        ->and($result->getOriginalResponse()->discardedBodyBytes === null)->toBe(!$discarding, $how);
                }

                // The request offers 204 whatever the caller put there, and sends the whole file.
                expect($server->requests[0]['headers']['allow'] ?? null)->toBe('204', $how)
                    ->and($server->requests[0]['stage'])->toBe('single', $how)
                    ->and($server->requests[0]['bodyBytes'])->toBe(5000, $how);
            } finally {
                $server->stop();
            }
        }
    }

    $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes('clean-204')]);
    try {
        cicapClient($server, true)->scanFile('/avscan', FileWorkers::file('abc'), ['allow' => 'nothing', 'X-Own' => 'kept'])->await();
        expect($server->requests[0]['headers']['allow'])->toBe('204')
            ->and($server->requests[0]['headers']['x-own'])->toBe('kept');
    } finally {
        $server->stop();
    }
});

it('scans with a preview, and takes only a finding as an answer to the preview of a file that is not all sent', function () {
    $whole = 'a file inside the preview';
    $partial = 'a file beyond the preview';

    // [the preview holds the file, answer to the preview, answer to the rest of the file, 'clean', 'infected' or the exception, whether the rest is sent]
    $cases = [
        // The preview holds the file, ieof: the answer is final.
        $whole . ': 204' => [true, 'clean-204', null, 'clean', false],
        $whole . ': EICAR' => [true, 'infected-200', null, 'infected', false],
        $whole . ': the message back' => [true, 'not-scanned-200', null, IcapProtocolException::class, false],
        $whole . ': 100 after ieof' => [true, 'continue-100', null, IcapProtocolException::class, false],
        // The file goes on: 100 Continue and the final answer.
        $partial . ': 100, 204' => [false, 'continue-100', 'clean-204', 'clean', true],
        $partial . ': 100, EICAR' => [false, 'continue-100', 'infected-200', 'infected', true],
        $partial . ': 100, a limit of the engine' => [false, 'continue-100', 'limit-exceeded-200', IcapProtocolException::class, true],
        $partial . ': 100, an encrypted archive' => [false, 'continue-100', 'encrypted-200', IcapProtocolException::class, true],
        $whole . ': an encrypted archive' => [true, 'encrypted-200', null, IcapProtocolException::class, false],
        $partial . ': 100, the message back' => [false, 'continue-100', 'not-scanned-200', IcapProtocolException::class, true],
        $partial . ': 100, a failing scanner' => [false, 'continue-100', 'error-500', IcapServerException::class, true],
        // An answer at once to the preview of a file that is not all there: a finding is one, anything else is not scanned.
        $partial . ': EICAR at once' => [false, 'infected-200', null, 'infected', false],
        $partial . ': 204 at once' => [false, 'clean-204', null, IcapProtocolException::class, false],
        $partial . ': the message back at once' => [false, 'not-scanned-200', null, IcapProtocolException::class, false],
        $partial . ': a failing scanner at once' => [false, 'error-500', null, IcapServerException::class, false],
    ];

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $transport => $sessions) {
        foreach ([false, true] as $discarding) {
            foreach ($cases as $label => [$inside, $previewAnswer, $restAnswer, $outcome, $sent]) {
                $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes($r['stage'] === 'preview' ? $previewAnswer : ($restAnswer ?? 'clean-204'))]);
                try {
                    $path = FileWorkers::file(str_repeat('a', $inside ? 500 : 5000));
                    $scan = cicapScan(true, $discarding);
                    $how = "{$transport}, " . ($discarding ? 'discarding' : 'buffering') . ": {$label}";

                    if ($outcome === 'clean' || $outcome === 'infected') {
                        $result = $scan(cicapClient($server, $sessions), $path);
                        expect($result->isInfected())->toBe($outcome === 'infected', $how)
                            ->and($result->getVirusName())->toBe($outcome === 'infected' ? cicapThreatOf($restAnswer ?? $previewAnswer) : null, $how);
                    } else {
                        expect(fn () => $scan(cicapClient($server, $sessions), $path))->toThrow($outcome, '', $how);
                    }

                    // After an answer that ends the exchange nothing of the rest of the file is sent, on this socket or in a request of its own.
                    $rest = $sessions ? 'rest' : 'single';
                    expect(array_column($server->requests, 'stage'))->toBe($sent ? ['preview', $rest] : ['preview'], $how)
                        ->and($server->requests[0]['headers']['allow'])->toBe('204', $how);
                    if ($sent && !$sessions) {
                        // The second request is a plain one: it offers 204 as well, or the service could only send the file back.
                        expect($server->requests[1]['headers']['allow'] ?? null)->toBe('204', $how)
                            ->and($server->requests[1]['headers'])->not->toHaveKey('preview', $how);
                    }
                } finally {
                    $server->stop();
                }
            }
        }
    }
});

it('does not give a socket back after an answer that is not a verdict', function () {
    // The pool of the transport offers a socket again only after a verdict that passed every check.
    $answers = [
        '204 at once' => cicapAnswerBytes('clean-204'),
        'the message back' => cicapAnswerBytes('not-scanned-200'),
        'a 100 that reports an infection' => cicapContinue("X-Infection-Found: Type=0; Resolution=2; Threat=Eicar-Test-Signature;\r\n"),
    ];
    foreach ($answers as $label => $answer) {
        $server = IcapTestServer::start(static fn (array $r) => [$r['stage'] === 'preview' ? $answer : cicapAnswerBytes('clean-204')]);
        $pool = new \Ndrstmr\Icap\Transport\AmpConnectionPool();
        try {
            $client = new IcapClient(
                new Config('127.0.0.1', $server->port, streamTimeout: 10.0),
                new AsyncAmpTransport($pool),
                new RequestFormatter(),
                new ResponseParser(),
                verdict: new CicapVerdict('/avscan'),
            );
            $path = FileWorkers::file(str_repeat('a', 5000));

            for ($scan = 1; $scan <= 2; $scan++) {
                expect(fn () => $client->scanFileWithPreview('/avscan', $path, 1024)->await())->toThrow(IcapProtocolException::class, '', $label);
            }
            expect($server->connections)->toBe(2, $label);
        } finally {
            $pool->close();
            $server->stop();
        }
    }
});

it('leaves the default mode as it is: it does not know the infection headers of this service', function () {
    // The same bytes, the same default Config, no verified mode: an infected file is a clean one, and so is a file that was not scanned.
    foreach (['EICAR' => 'infected-200', 'the message back' => 'not-scanned-200'] as $label => $answer) {
        $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes($answer)]);
        try {
            $result = cicapClient($server, true, verified: false)->scanFile('/avscan', FileWorkers::file(str_repeat('a', 5000)))->await();
            expect($result->isInfected())->toBeFalse($label)
                ->and($server->requests[0]['headers'])->not->toHaveKey('allow', $label);
        } finally {
            $server->stop();
        }
    }

    $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes('clean-204')]);
    try {
        $result = cicapClient($server, true, verified: false)->scanFileWithPreview('/avscan', FileWorkers::file(str_repeat('a', 5000)), 1024)->await();
        expect($result->isInfected())->toBeFalse()
            ->and(array_column($server->requests, 'stage'))->toBe(['preview']);
    } finally {
        $server->stop();
    }
});

it('gives the same result through the wrappers of the client', function () {
    $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes($r['stage'] === 'preview' ? 'continue-100' : 'infected-200')]);
    try {
        $client = cicapClient($server, true);
        $path = FileWorkers::file(str_repeat('a', 5000));

        $wrappers = [
            'retrying' => fn () => (new RetryingIcapClient($client))->scanFileWithPreview('/avscan', $path, 1024)->await(),
            'retrying, discarding' => fn () => (new RetryingIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024)->await(),
            'synchronous' => fn () => (new SynchronousIcapClient($client))->scanFileWithPreview('/avscan', $path, 1024),
            'synchronous, discarding' => fn () => (new SynchronousIcapClient($client))->scanFileWithPreviewDiscardingBody('/avscan', $path, 1 << 20, 1024),
        ];
        foreach ($wrappers as $label => $scan) {
            $result = $scan();
            expect($result->isInfected())->toBeTrue($label)
                ->and($result->getVirusName())->toBe('Eicar-Test-Signature', $label);
        }
    } finally {
        $server->stop();
    }

    $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes('clean-204')]);
    try {
        $client = cicapClient($server, true);
        $path = FileWorkers::file(str_repeat('a', 5000));
        expect(fn () => (new RetryingIcapClient($client))->scanFileWithPreview('/avscan', $path, 1024)->await())->toThrow(IcapProtocolException::class)
            ->and(fn () => (new SynchronousIcapClient($client))->scanFileWithPreview('/avscan', $path, 1024))->toThrow(IcapProtocolException::class);
    } finally {
        $server->stop();
    }
});

it('does not let a preview strategy of its own decide before the verified mode does', function () {
    $own = new class () implements PreviewStrategyInterface {
        #[\Override]
        public function handlePreviewResponse(\Ndrstmr\Icap\DTO\IcapResponse $previewResponse): PreviewDecision
        {
            return PreviewDecision::ABORT_CLEAN;
        }
    };
    $make = static fn (?PreviewStrategyInterface $strategy): IcapClient => new IcapClient(
        new Config('icap.example'),
        new AsyncAmpTransport(),
        new RequestFormatter(),
        new ResponseParser(),
        $strategy,
        verdict: new CicapVerdict('/avscan'),
    );

    expect(fn () => $make($own))->toThrow(\InvalidArgumentException::class)
        ->and($make(null))->toBeInstanceOf(IcapClient::class)
        ->and($make(new DefaultPreviewStrategy()))->toBeInstanceOf(IcapClient::class);
});

it('takes a request() for scanned only if the request sent the whole message', function () {
    $threat = 'Eicar-Test-Signature';
    $start = ['Preview' => '1', 'Allow' => '204'];
    // [ICAP fields, body that goes out, length the message announces, ends in ieof, answer, 'clean', the threat or the exception]
    $cases = [
        'the whole message' => [['Allow' => '204'], 'abcdefgh', 8, false, 'clean-204', 'clean'],
        'the whole message in a preview that ends in ieof' => [['Preview' => '8', 'Allow' => '204'], 'abcdefgh', 8, true, 'clean-204', 'clean'],
        'the whole message, EICAR' => [['Allow' => '204'], 'abcdefgh', 8, false, 'infected-200', $threat],
        'the start of the message, 204' => [$start, 'a', 8, false, 'clean-204', IcapProtocolException::class],
        'the start of the message, 204, the fields in another spelling' => [['preview' => '1', 'ALLOW' => '204'], 'a', 8, false, 'clean-204', IcapProtocolException::class],
        'an empty start of the message, 204' => [['Preview' => '0', 'Allow' => '204'], '', 8, false, 'clean-204', IcapProtocolException::class],
        'the start of the message, EICAR at once' => [$start, 'a', 8, false, 'infected-200', $threat],
        'the start of the message, the message back' => [$start, 'a', 8, false, 'not-scanned-200', IcapProtocolException::class],
        'the start of the message, 100, which request() cannot answer' => [$start, 'a', 8, false, 'continue-100', IcapProtocolException::class],
    ];

    foreach (['one socket' => true, 'one request per connection' => false] as $transport => $sessions) {
        foreach ($cases as $label => [$headers, $body, $announced, $ieof, $answer, $outcome]) {
            $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes($answer)]);
            try {
                $client = cicapClient($server, $sessions);
                $request = cicapRawRequest("icap://127.0.0.1:{$server->port}/avscan", $headers, $body, $announced, $ieof);
                $how = "{$transport}: {$label}";

                if ($outcome === 'clean' || $outcome === $threat) {
                    $result = $client->request($request)->await();
                    expect($result->isInfected())->toBe($outcome === $threat, $how)
                        ->and($result->getVirusName())->toBe($outcome === $threat ? $threat : null, $how);
                } else {
                    expect(fn () => $client->request($request)->await())->toThrow($outcome, '', $how);
                }

                // What the service answered to is what the server took in: the start of the message, or all of it.
                expect($server->requests[0]['bodyBytes'])->toBe(strlen($body), $how);
            } finally {
                $server->stop();
            }
        }
    }
});

it('does not send the rest of the file when the 100 Continue reports an infection, and sends it when the 100 is none of that', function () {
    $found = 'Type=0; Resolution=2; Threat=Eicar-Test-Signature;';
    $folded = "X-Violations-Found: 1\r\n\t-\r\n\tEicar-Test-Signature\r\n\t0\r\n\t0\r\n";
    $refused = [
        'an infection' => "X-Infection-Found: {$found}\r\n",
        'the pair of c-icap' => "X-Infection-Found: {$found}\r\n{$folded}",
        'violations' => $folded,
        'the field in another spelling' => "x-infection-found: {$found}\r\n",
        'a diagnostic of the engine' => "X-Infection-Found: Type=0; Resolution=2; Threat=Heuristics.Encrypted.Zip;\r\n",
        'an infection header that is malformed' => "X-Infection-Found: not what c-icap sends\r\n",
        'headers that contradict each other' => "X-Infection-Found: {$found}\r\nX-Violations-Found: 0\r\n",
    ];
    $allowed = [
        'the 100 of c-icap, which has no fields' => '',
        'a 100 with fields of its own' => "ISTag: \"x\"\r\nConnection: keep-alive\r\n",
        'a 100 that reports no violations' => "X-Violations-Found: 0\r\n",
    ];

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $transport => $sessions) {
        foreach ([false, true] as $discarding) {
            foreach (['refused' => $refused, 'allowed' => $allowed] as $kind => $set) {
                foreach ($set as $label => $fields) {
                    $server = IcapTestServer::start(static fn (array $r) => [$r['stage'] === 'preview' ? cicapContinue($fields) : cicapAnswerBytes('clean-204')]);
                    try {
                        $path = FileWorkers::file(str_repeat('a', 5000));
                        $scan = cicapScan(true, $discarding);
                        $how = "{$transport}, " . ($discarding ? 'discarding' : 'buffering') . ": {$label}";

                        if ($kind === 'allowed') {
                            expect($scan(cicapClient($server, $sessions), $path)->isInfected())->toBeFalse($how)
                                ->and(array_column($server->requests, 'stage'))->toBe(['preview', $sessions ? 'rest' : 'single'], $how);
                        } else {
                            // Neither the rest of the file on this socket nor a request of its own with it.
                            expect(fn () => $scan(cicapClient($server, $sessions), $path))->toThrow(IcapProtocolException::class, '', $how)
                                ->and(array_column($server->requests, 'stage'))->toBe(['preview'], $how);
                        }
                    } finally {
                        $server->stop();
                    }
                }
            }
        }
    }
});

it('is bound to the service of its verdict, and refuses another one before a file or a connection is touched', function () {
    $server = IcapTestServer::start(static fn (array $r) => [cicapAnswerBytes('clean-204')]);
    try {
        $missing = sys_get_temp_dir() . '/icap-flow-missing-' . bin2hex(random_bytes(8));
        $uri = static fn (string $service): string => "icap://127.0.0.1:{$server->port}{$service}";
        // The path and the query of the service count, nothing else does.
        $bound = [
            '/avscan' => ['/avscan_typed', '/avscan?force=on', '/avscan?mode=simple', '/AVSCAN', '/avscan/', '/'],
            '/avscan?mode=simple' => ['/avscan', '/avscan?mode=simple&force=on', '/avscan?mode=Simple'],
        ];

        foreach ($bound as $service => $others) {
            $client = cicapClient($server, true, service: $service);
            foreach ($others as $other) {
                $calls = [
                    'request' => fn () => $client->request(cicapRawRequest($uri($other), ['Allow' => '204'], 'abc', 3)),
                    'request, scheme in capitals' => fn () => $client->request(cicapRawRequest(strtoupper('icaps') . substr($uri($other), 4), ['Allow' => '204'], 'abc', 3)),
                    'scanFile' => fn () => $client->scanFile($other, $missing),
                    'scanFileDiscardingBody' => fn () => $client->scanFileDiscardingBody($other, $missing, 1 << 20),
                    'scanFileWithPreview' => fn () => $client->scanFileWithPreview($other, $missing, 1024),
                    'scanFileWithPreviewDiscardingBody' => fn () => $client->scanFileWithPreviewDiscardingBody($other, $missing, 1 << 20, 1024),
                    'retrying' => fn () => (new RetryingIcapClient($client))->scanFile($other, $missing)->await(),
                    'synchronous' => fn () => (new SynchronousIcapClient($client))->scanFile($other, $missing),
                ];
                foreach ($calls as $name => $call) {
                    expect($call)->toThrow(\InvalidArgumentException::class, '', "{$service} asked for {$other}: {$name}");
                }
            }
        }
        // A URI without a service names none, however it is built.
        $client = cicapClient($server, true);
        foreach (["icap://127.0.0.1:{$server->port}", "icap://127.0.0.1:{$server->port}?service=/avscan", "icap://127.0.0.1:{$server->port}//avscan"] as $nameless) {
            expect(fn () => $client->request(cicapRawRequest($nameless, ['Allow' => '204'], 'abc', 3)))->toThrow(\InvalidArgumentException::class, '', $nameless);
        }
        expect($server->connections)->toBe(0)->and($server->requests)->toBe([]);

        // Its own service works the same way through each of them. The endpoint is the one of the Config, whatever the URI says.
        foreach (array_keys($bound) as $service) {
            $client = cicapClient($server, true, service: $service);
            $small = FileWorkers::file(str_repeat('a', 500));
            foreach ([
                'request' => $client->request(cicapRawRequest("icap://other.example:9{$service}", ['Allow' => '204'], 'abc', 3))->await(),
                'request, scheme in capitals' => $client->request(cicapRawRequest("ICAPS://Other.Example:9{$service}", ['Allow' => '204'], 'abc', 3))->await(),
                'scanFile' => $client->scanFile($service, $small)->await(),
                'scanFileDiscardingBody' => $client->scanFileDiscardingBody($service, $small, 1 << 20)->await(),
                'scanFileWithPreview' => $client->scanFileWithPreview($service, $small, 1024)->await(),
                'scanFileWithPreviewDiscardingBody' => $client->scanFileWithPreviewDiscardingBody($service, $small, 1 << 20, 1024)->await(),
            ] as $name => $result) {
                expect($result->isInfected())->toBeFalse("{$service}: {$name}");
            }
        }
        expect($server->requests[0]['headers']['host'])->toBe('other.example:9');
    } finally {
        $server->stop();
    }
});

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

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DefaultPreviewStrategy;
use Ndrstmr\Icap\DTO\HttpRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\VerdictInterface;
use Ndrstmr\Icap\VirusHeaderVerdict;
use PHPUnit\Framework\Assert;

/*
 * Neutral ICAP response fixtures and tests for VerdictInterface and VirusHeaderVerdict:
 *  - 204 without Encapsulated (clean only when the whole file was sent, no virus/block headers)
 *  - 200 / 206 with a unique non-empty virus header (finding, also alongside a block header)
 *  - 200 / 206 without virus header and with a configured non-empty block header (policy block: isInfected=true, virusName=null, blockReason set)
 *  - 200 / 206 unmodified, contradictory or empty headers, 100 with virus/block headers -> IcapProtocolException
 *  - 418 -> IcapClientException, 500 -> IcapServerException, unexpected statuses -> IcapProtocolException before verdict
 */

afterEach(function (): void {
    FileWorkers::removeFiles();
});

/**
 * Builds neutral wire-format ICAP responses for a header-signalling gateway.
 *
 * @param array<string, string|list<string>> $extraHeaders
 */
function verdictWireResponse(
    int $status,
    string $reason,
    array $extraHeaders = [],
    ?string $httpStatusLine = null,
    ?string $body = null,
    bool $withEncapsulated = true,
): string {
    $head = "ICAP/1.0 {$status} {$reason}\r\nISTag: \"fixture\"\r\n";
    foreach ($extraHeaders as $name => $values) {
        foreach (is_array($values) ? $values : [$values] as $value) {
            $head .= "{$name}: {$value}\r\n";
        }
    }

    if ($httpStatusLine !== null) {
        $payload = $body ?? '';
        $resHdr = "{$httpStatusLine}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($payload) . "\r\n\r\n";
        $head .= 'Encapsulated: res-hdr=0, res-body=' . strlen($resHdr) . "\r\n\r\n";
        $chunk = $payload === '' ? "0\r\n\r\n" : dechex(strlen($payload)) . "\r\n{$payload}\r\n0\r\n\r\n";

        return $head . $resHdr . $chunk;
    }

    if ($withEncapsulated) {
        $head .= "Encapsulated: null-body=0\r\n";
    }

    return $head . "\r\n";
}

/**
 * Neutral response fixtures mirroring a gateway that requires req-hdr and signals findings and blocks via headers.
 *
 * @return array<string, string>
 */
function gatewayWireFixtures(): array
{
    return [
        // 418 when RESPMOD arrives without req-hdr
        'bad-composition-418' => verdictWireResponse(418, 'Bad composition'),
        // Clean 204 without an Encapsulated header, carrying an unconfigured media-type header
        'clean-204-no-encap' => verdictWireResponse(
            204,
            'No modification needed',
            ['X-Media-Type' => 'text/plain'],
            withEncapsulated: false,
        ),
        // 200 infection: carries virus header alongside block-reason and unconfigured headers plus HTML block page
        'infected-200' => verdictWireResponse(
            200,
            'OK',
            [
                'X-Media-Type' => 'text/plain',
                'X-Virus-Name' => 'EICAR test file',
                'X-Block-Reason' => 'Malware found',
                'X-WWBlockResult' => '80',
            ],
            'HTTP/1.1 403 VirusFound',
            '<html><body>Virus found</body></html>',
        ),
        // 200 policy block: no virus header, only block-reason and unconfigured headers plus HTML block page
        'blocked-200' => verdictWireResponse(
            200,
            'OK',
            [
                'X-Media-Type' => 'application/x-msdownload',
                'X-Block-Reason' => 'Media type blocked',
                'X-WWBlockResult' => '80',
            ],
            'HTTP/1.1 403 MediaTypeBlocked',
            '<html><body>Media type blocked</body></html>',
        ),
        // 200 unmodified message: neither virus nor block header
        'unmodified-200' => verdictWireResponse(
            200,
            'OK',
            ['X-Media-Type' => 'text/plain'],
            'HTTP/1.1 200 OK',
            'unchanged payload',
        ),
        // 500 server error with Connection: close and no Encapsulated header
        'error-500' => verdictWireResponse(
            500,
            'Server error',
            ['Connection' => 'close'],
            withEncapsulated: false,
        ),
        // 100 Continue without verdict headers
        'continue-100' => verdictWireResponse(100, 'Continue', withEncapsulated: false),
    ];
}

it('validates service, virus header names and optional block header names in the constructor', function () {
    $default = new VirusHeaderVerdict('/avscan');
    expect($default)->toBeInstanceOf(VerdictInterface::class)
        ->and($default->service())->toBe('/avscan')
        ->and((new VirusHeaderVerdict('/avscan?profile=strict', ['X-Virus-Name', 'X-Virus-ID'], ['X-Block-Reason']))->service())->toBe('/avscan?profile=strict')
        ->and((new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], []))->service())->toBe('/avscan');

    foreach (['', 'avscan', '/avscan#frag', "/avscan\r\nHost: evil", '/av scan', 'icap://localhost/avscan'] as $service) {
        expect(fn () => new VirusHeaderVerdict($service))->toThrow(\InvalidArgumentException::class, '', var_export($service, true));
    }

    // Callers without static analysis can pass untyped arrays.
    $withVirus = static fn ($names): VirusHeaderVerdict => new VirusHeaderVerdict('/avscan', $names);
    $withBlock = static fn ($names): VirusHeaderVerdict => new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], $names);

    $badLists = [
        'empty header name' => [''],
        'header name with space' => ['X Virus'],
        'header name with colon' => ['X-Virus:'],
        'header name with CRLF' => ["X-Virus\r\nX-Injected: 1"],
        'header name ending in LF' => ["X-Virus-Name\n"],
        'int instead of string' => [42],
        'null instead of string' => [null],
        'nested array' => [['X-Virus-Name']],
        'bad after good' => ['X-Virus-Name', 'X Bad'],
        'string keys' => ['primary' => 'X-Virus-Name'],
        'gap in keys' => [0 => 'X-Virus-Name', 2 => 'X-Other'],
    ];

    expect(fn () => $withVirus([]))->toThrow(\InvalidArgumentException::class, 'virusHeaders');
    foreach ($badLists as $label => $names) {
        expect(fn () => $withVirus($names))->toThrow(\InvalidArgumentException::class, 'virusHeaders', "virus: {$label}")
            ->and(fn () => $withBlock($names))->toThrow(\InvalidArgumentException::class, 'blockHeaders', "block: {$label}");
    }
});

it('plugs any VerdictInterface into IcapClient and forServer(), enforces its service, status boundary and preview strategy, and sends Allow: 204', function () {
    $custom = new class ('/custom') implements VerdictInterface {
        public int $interpreted = 0;

        public function __construct(private readonly string $service)
        {
        }

        #[\Override]
        public function service(): string
        {
            return $this->service;
        }

        #[\Override]
        public function interpret(IcapResponse $response, bool $complete): ScanResult
        {
            $this->interpreted++;

            return new ScanResult(false, null, $response);
        }

        #[\Override]
        public function assertContinuation(IcapResponse $response): void
        {
        }
    };

    // Custom preview strategy is refused with any VerdictInterface; DefaultPreviewStrategy is allowed.
    $ownStrategy = new class () implements PreviewStrategyInterface {
        #[\Override]
        public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
        {
            return PreviewDecision::ABORT_CLEAN;
        }
    };
    expect(fn () => new IcapClient(
        new Config('icap.example'),
        new ScriptedTransport(),
        new RequestFormatter(),
        new ResponseParser(),
        $ownStrategy,
        verdict: $custom,
    ))->toThrow(\InvalidArgumentException::class);

    // Every status outside 200/204/206 is rejected by IcapClient before the verdict is called.
    $nonVerdictStatuses = [
        100 => IcapProtocolException::class,
        101 => IcapProtocolException::class,
        201 => IcapProtocolException::class,
        302 => IcapProtocolException::class,
        418 => IcapClientException::class,
        500 => IcapServerException::class,
        600 => IcapProtocolException::class,
    ];
    $responses = [];
    foreach (array_keys($nonVerdictStatuses) as $code) {
        $responses[] = verdictWireResponse($code, 'Status', withEncapsulated: $code !== 100);
    }
    $responses[] = gatewayWireFixtures()['clean-204-no-encap'];

    $transport = new ScriptedTransport($responses);
    $client = new IcapClient(
        new Config('icap.example'),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
        new DefaultPreviewStrategy(),
        verdict: $custom,
    );

    // Another service is refused before any file or socket is touched.
    expect(fn () => $client->scanFile('/other', '/nonexistent/path'))->toThrow(\InvalidArgumentException::class)
        ->and($transport->requests)->toBe([]);

    $file = FileWorkers::file('abc');
    foreach ($nonVerdictStatuses as $code => $exceptionClass) {
        try {
            $client->scanFile('/custom', $file)->await();
            Assert::fail("Expected {$exceptionClass} for status {$code}");
        } catch (\Throwable $e) {
            expect($e)->toBeInstanceOf($exceptionClass, "status {$code}")
                ->and($e->getCode())->toBe($code, "status {$code}");
        }
    }
    expect($custom->interpreted)->toBe(0);

    // The bound service runs through the verdict on 204 and sends Allow: 204.
    $result = $client->scanFile('/custom', $file)->await();
    expect($result->isInfected())->toBeFalse()
        ->and($custom->interpreted)->toBe(1)
        ->and($transport->requests[0])->toContain("Allow: 204\r\n");

    // IcapClient::forServer() accepts any VerdictInterface (custom or VirusHeaderVerdict).
    expect(IcapClient::forServer('icap.example', 1344, $custom))->toBeInstanceOf(IcapClient::class)
        ->and(IcapClient::forServer('icap.example', 1344, new VirusHeaderVerdict('/avscan')))->toBeInstanceOf(IcapClient::class);
});

it('evaluates every rule of VirusHeaderVerdict cleanly, case-insensitively and across all header occurrences', function () {
    $parser = new ResponseParser();
    $fixtures = gatewayWireFixtures();
    $verdict = new VirusHeaderVerdict('/avscan', ['X-Virus-Name', 'X-Virus-ID'], ['X-Block-Reason', 'X-Policy-Block']);
    $noBlockVerdict = new VirusHeaderVerdict('/avscan', ['X-Virus-Name']);

    // 204 on complete file, without virus or block headers (including 204 without Encapsulated and with unconfigured headers).
    foreach ([
        '204 without Encapsulated' => $parser->parse($fixtures['clean-204-no-encap']),
        '204 with Encapsulated' => $parser->parse(verdictWireResponse(204, 'No Content')),
        '204 with unconfigured block header when blockHeaders is empty' => $parser->parse(verdictWireResponse(204, 'No Content', ['X-Block-Reason' => 'Ignored'])),
    ] as $label => $response) {
        $v = str_contains($label, 'blockHeaders is empty') ? $noBlockVerdict : $verdict;
        $result = $v->interpret($response, complete: true);
        expect($result->isInfected())->toBeFalse($label)
            ->and($result->getVirusName())->toBeNull($label)
            ->and($result->getBlockReason())->toBeNull($label);
    }

    // 204 before the whole file was sent -> IcapProtocolException (204).
    expect(fn () => $verdict->interpret($parser->parse($fixtures['clean-204-no-encap']), complete: false))
        ->toThrow(IcapProtocolException::class);

    // 204 with virus or block header (any case, empty or non-empty) -> contradiction -> IcapProtocolException (204).
    foreach ([
        '204 with virus header' => ['X-Virus-Name' => 'EICAR'],
        '204 with lower-case virus header' => ['x-virus-name' => 'EICAR'],
        '204 with second virus header' => ['x-virus-id' => 'EICAR'],
        '204 with empty virus header' => ['X-Virus-Name' => ''],
        '204 with block header' => ['X-Block-Reason' => 'Blocked'],
        '204 with upper-case block header' => ['X-POLICY-BLOCK' => 'Blocked'],
        '204 with empty block header' => ['X-Block-Reason' => ''],
    ] as $label => $headers) {
        foreach ([true, false] as $complete) {
            try {
                $verdict->interpret($parser->parse(verdictWireResponse(204, 'No Content', $headers)), $complete);
                $thrown = null;
            } catch (IcapProtocolException $e) {
                $thrown = $e;
            }
            expect($thrown)->toBeInstanceOf(IcapProtocolException::class, $label)
                ->and($thrown?->getCode())->toBe(204, $label);
        }
    }

    // 200 / 206 with one virus name (single, case-insensitive, repeated identical across spellings/headers, or alongside block header).
    $findingCases = [
        'fixture with virus and block header' => [$parser->parse($fixtures['infected-200']), 'EICAR test file'],
        'single lower-case virus header' => [$parser->parse(verdictWireResponse(200, 'OK', ['x-virus-name' => 'Win.Test.EICAR'])), 'Win.Test.EICAR'],
        'repeated identical virus name across cases' => [$parser->parse(verdictWireResponse(200, 'OK', ['X-Virus-Name' => 'EICAR', 'x-virus-name' => 'EICAR'])), 'EICAR'],
        'repeated identical virus name across two configured headers' => [$parser->parse(verdictWireResponse(206, 'Partial Content', ['X-Virus-Name' => 'EICAR', 'x-virus-id' => 'EICAR'])), 'EICAR'],
        'trimmed whitespace around direct DTO value' => [new IcapResponse(200, ['X-Virus-Name' => ['  EICAR  ', "\tEICAR\t"]]), 'EICAR'],
    ];
    foreach ($findingCases as $label => [$response, $expectedName]) {
        foreach ([true, false] as $complete) {
            $result = $verdict->interpret($response, $complete);
            expect($result->isInfected())->toBeTrue($label)
                ->and($result->getVirusName())->toBe($expectedName, $label)
                ->and($result->getBlockReason())->toBeNull($label);
        }
    }

    // 200 / 206 with differing or empty virus names (even when a block header is also present) -> IcapProtocolException.
    $invalidVirusCases = [
        'conflicting values in same header' => ['X-Virus-Name' => ['Virus.A', 'Virus.B']],
        'conflicting values across spellings' => ['X-Virus-Name' => 'Virus.A', 'x-virus-name' => 'Virus.B'],
        'conflicting values across two virus headers' => ['X-Virus-Name' => 'Virus.A', 'X-Virus-ID' => 'Virus.B'],
        'empty virus header alone' => ['X-Virus-Name' => ''],
        'whitespace-only virus header' => ['X-Virus-Name' => '   '],
        'empty virus header alongside valid one' => ['X-Virus-Name' => 'Virus.A', 'X-Virus-ID' => ''],
        'empty virus header alongside valid block header' => ['X-Virus-Name' => '', 'X-Block-Reason' => 'Media type blocked'],
        'conflicting virus headers alongside valid block header' => ['X-Virus-Name' => ['A', 'B'], 'X-Block-Reason' => 'Malware'],
    ];
    foreach ($invalidVirusCases as $label => $headers) {
        foreach ([200, 206] as $status) {
            foreach ([true, false] as $complete) {
                $response = new IcapResponse($status, array_map(static fn (string|array $v): array => is_array($v) ? $v : [$v], $headers));
                expect(fn () => $verdict->interpret($response, $complete))
                    ->toThrow(IcapProtocolException::class, '', "{$label} ({$status})");
            }
        }
    }

    // 200 / 206 without virus header, with non-empty configured block reason -> policy block (isInfected=true, virusName=null, blockReason set).
    $blockCases = [
        'fixture policy block' => [$parser->parse($fixtures['blocked-200']), 'Media type blocked'],
        'lower-case block header on 206' => [$parser->parse(verdictWireResponse(206, 'Partial Content', ['x-block-reason' => 'Policy denied'])), 'Policy denied'],
        'repeated identical block reason across headers' => [$parser->parse(verdictWireResponse(200, 'OK', ['X-Block-Reason' => 'Denied', 'x-policy-block' => 'Denied'])), 'Denied'],
    ];
    foreach ($blockCases as $label => [$response, $expectedReason]) {
        foreach ([true, false] as $complete) {
            $result = $verdict->interpret($response, $complete);
            expect($result->isInfected())->toBeTrue($label)
                ->and($result->getVirusName())->toBeNull($label)
                ->and($result->getBlockReason())->toBe($expectedReason, $label);
        }
    }

    // 200 / 206 with empty/conflicting block header, unconfigured block header, or neither -> IcapProtocolException.
    $invalidBlockOrUnmodified = [
        'empty block header' => [$verdict, new IcapResponse(200, ['X-Block-Reason' => ['']])],
        'whitespace-only block header' => [$verdict, new IcapResponse(200, ['X-Block-Reason' => [" \t "]])],
        'conflicting block headers' => [$verdict, new IcapResponse(200, ['X-Block-Reason' => ['Reason A'], 'X-Policy-Block' => ['Reason B']])],
        'empty block header alongside valid one' => [$verdict, new IcapResponse(200, ['X-Block-Reason' => ['Reason A', '']])],
        'block header not configured stays error' => [$noBlockVerdict, $parser->parse($fixtures['blocked-200'])],
        'unmodified 200 fixture' => [$verdict, $parser->parse($fixtures['unmodified-200'])],
        '206 without virus or block headers' => [$verdict, new IcapResponse(206, [], '', null, 403)],
    ];
    foreach ($invalidBlockOrUnmodified as $label => [$v, $response]) {
        foreach ([true, false] as $complete) {
            expect(fn () => $v->interpret($response, $complete))->toThrow(IcapProtocolException::class, '', $label);
        }
    }

    // 100 Continue: clean allowed; virus or configured block header (any case, empty or non-empty) rejected with code 100.
    expect(fn () => $verdict->assertContinuation($parser->parse($fixtures['continue-100'])))->not->toThrow(IcapProtocolException::class)
        ->and(fn () => $noBlockVerdict->assertContinuation(new IcapResponse(100, ['X-Block-Reason' => ['Ignored']])))->not->toThrow(IcapProtocolException::class);

    foreach ([
        '100 with virus header' => ['X-Virus-Name' => ['EICAR']],
        '100 with lower-case virus header' => ['x-virus-id' => ['EICAR']],
        '100 with empty virus header' => ['X-Virus-Name' => ['']],
        '100 with block header' => ['X-Block-Reason' => ['Blocked']],
        '100 with empty block header' => ['x-policy-block' => ['']],
    ] as $label => $headers) {
        try {
            $verdict->assertContinuation(new IcapResponse(100, $headers));
            $thrown = null;
        } catch (IcapProtocolException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeInstanceOf(IcapProtocolException::class, $label)
            ->and($thrown?->getCode())->toBe(100, $label);
    }
});

it('never leaks server header values into exception messages', function () {
    $verdict = new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason']);
    $secret = 'SECRET-SERVER-TEXT-' . bin2hex(random_bytes(4));

    $interpretCases = [
        '204 with virus' => [new IcapResponse(204, ['X-Virus-Name' => [$secret]]), true],
        '204 with block' => [new IcapResponse(204, ['X-Block-Reason' => [$secret]]), true],
        '204 incomplete' => [new IcapResponse(204, ['X-Note' => [$secret]]), false],
        '200 conflicting virus' => [new IcapResponse(200, ['X-Virus-Name' => [$secret . '-a', $secret . '-b']]), true],
        '200 conflicting block' => [new IcapResponse(200, ['X-Block-Reason' => [$secret . '-a', $secret . '-b']]), true],
        '200 unmodified with body' => [new IcapResponse(200, ['X-Note' => [$secret]], $secret, null, 403), true],
    ];
    foreach ($interpretCases as $label => [$response, $complete]) {
        try {
            $verdict->interpret($response, $complete);
            Assert::fail("Expected exception for {$label}");
        } catch (IcapProtocolException $e) {
            expect($e->getMessage())->not->toBe('', $label)
                ->and($e->getMessage())->not->toContain($secret, $label);
        }
    }

    foreach ([
        '100 with virus' => new IcapResponse(100, ['X-Virus-Name' => [$secret]]),
        '100 with block' => new IcapResponse(100, ['X-Block-Reason' => [$secret]]),
    ] as $label => $response) {
        try {
            $verdict->assertContinuation($response);
            Assert::fail("Expected exception for {$label}");
        } catch (IcapProtocolException $e) {
            expect($e->getMessage())->not->toBe('', $label)
                ->and($e->getMessage())->not->toContain($secret, $label);
        }
    }

    // 4xx and 5xx handled by IcapClient also keep server text out of exception messages.
    $client = new IcapClient(
        new Config('icap.example'),
        new ScriptedTransport([
            verdictWireResponse(418, $secret, ['X-Note' => $secret]),
            verdictWireResponse(500, $secret, ['X-Note' => $secret], withEncapsulated: false),
        ]),
        new RequestFormatter(),
        new ResponseParser(),
        verdict: $verdict,
    );
    $file = FileWorkers::file('abc');
    foreach ([418 => IcapClientException::class, 500 => IcapServerException::class] as $code => $class) {
        try {
            $client->scanFile('/avscan', $file)->await();
            Assert::fail("Expected {$class} for {$code}");
        } catch (\Throwable $e) {
            expect($e)->toBeInstanceOf($class, "status {$code}")
                ->and($e->getMessage())->not->toContain($secret, "status {$code}");
        }
    }
});

it('applies VirusHeaderVerdict through scanFile(), previews, discarding helpers, and both transports', function () {
    $fixtures = gatewayWireFixtures();
    $fixtures['continue-100-virus'] = verdictWireResponse(100, 'Continue', ['X-Virus-Name' => 'EICAR'], withEncapsulated: false);
    $fixtures['continue-100-block'] = verdictWireResponse(100, 'Continue', ['X-Block-Reason' => 'Denied'], withEncapsulated: false);

    $head = new HttpRequest('GET', '/upload', ['Host' => ['localhost']]);
    $clean = ['infected' => false, 'virus' => null, 'block' => null];
    $infected = ['infected' => true, 'virus' => 'EICAR test file', 'block' => null];
    $blocked = ['infected' => true, 'virus' => null, 'block' => 'Media type blocked'];

    // [mode ('whole'|'whole-discard'|'preview-ieof'|'preview-partial'|'preview-partial-discard'), firstAnswer, restAnswer, expectedOutcome, expectedStages]
    $matrix = [
        'whole: clean 204 (no Encapsulated)' => ['whole', 'clean-204-no-encap', null, $clean, ['single']],
        'whole: infected 200' => ['whole', 'infected-200', null, $infected, ['single']],
        'whole: blocked 200' => ['whole', 'blocked-200', null, $blocked, ['single']],
        'whole: unmodified 200' => ['whole', 'unmodified-200', null, ['exception' => IcapProtocolException::class, 'code' => 200], ['single']],
        'whole: error 500' => ['whole', 'error-500', null, ['exception' => IcapServerException::class, 'code' => 500], ['single']],
        'whole discarding: blocked 200' => ['whole-discard', 'blocked-200', null, $blocked, ['single']],
        'preview ieof: clean 204' => ['preview-ieof', 'clean-204-no-encap', null, $clean, ['preview']],
        'preview partial: 100 then clean 204' => ['preview-partial', 'continue-100', 'clean-204-no-encap', $clean, ['preview', 'rest']],
        'preview partial: 100 then infected 200' => ['preview-partial', 'continue-100', 'infected-200', $infected, ['preview', 'rest']],
        'preview partial: 100 then blocked 200' => ['preview-partial', 'continue-100', 'blocked-200', $blocked, ['preview', 'rest']],
        'preview partial discarding: 100 then blocked 200' => ['preview-partial-discard', 'continue-100', 'blocked-200', $blocked, ['preview', 'rest']],
        'preview partial: 204 before whole file' => ['preview-partial', 'clean-204-no-encap', null, ['exception' => IcapProtocolException::class, 'code' => 204], ['preview']],
        'preview partial: infected at once' => ['preview-partial', 'infected-200', null, $infected, ['preview']],
        'preview partial: blocked at once' => ['preview-partial', 'blocked-200', null, $blocked, ['preview']],
        'preview partial: 100 with virus header stops before rest' => ['preview-partial', 'continue-100-virus', null, ['exception' => IcapProtocolException::class, 'code' => 100], ['preview']],
        'preview partial: 100 with block header stops before rest' => ['preview-partial', 'continue-100-block', null, ['exception' => IcapProtocolException::class, 'code' => 100], ['preview']],
    ];

    $syncScript = <<<'PHP'
        require $argv[1];
        $job = json_decode($argv[2], true);
        $client = new Ndrstmr\Icap\IcapClient(
            new Ndrstmr\Icap\Config('127.0.0.1', $job['port'], socketTimeout: 2.0, streamTimeout: 5.0, operationTimeout: 8.0),
            new Ndrstmr\Icap\Transport\SynchronousStreamTransport(),
            new Ndrstmr\Icap\RequestFormatter(),
            new Ndrstmr\Icap\ResponseParser(),
            verdict: new Ndrstmr\Icap\VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason']),
            encapsulatedRequest: new Ndrstmr\Icap\DTO\HttpRequest('GET', '/upload', ['Host' => ['localhost']]),
        );
        try {
            $result = match ($job['mode']) {
                'whole' => $client->scanFile('/avscan', $job['file'])->await(),
                'whole-discard' => $client->scanFileDiscardingBody('/avscan', $job['file'], 1 << 20)->await(),
                'preview-ieof', 'preview-partial' => $client->scanFileWithPreview('/avscan', $job['file'], 1024)->await(),
                'preview-partial-discard' => $client->scanFileWithPreviewDiscardingBody('/avscan', $job['file'], 1 << 20, 1024)->await(),
            };
            echo json_encode([
                'infected' => $result->isInfected(),
                'virus' => $result->getVirusName(),
                'block' => $result->getBlockReason(),
            ]), "\n";
        } catch (Throwable $e) {
            echo json_encode(['exception' => $e::class, 'code' => $e->getCode()]), "\n";
        }
        PHP;

    $smallFile = FileWorkers::file(str_repeat('a', 500));
    $largeFile = FileWorkers::file(str_repeat('b', 5000));

    foreach (['async', 'sync'] as $transport) {
        foreach ($matrix as $label => [$mode, $firstKey, $restKey, $expected, $expectedStages]) {
            $server = IcapTestServer::start(static fn (array $r): array => [
                $fixtures[$r['stage'] === 'rest' ? ($restKey ?? $firstKey) : $firstKey],
            ]);
            try {
                $file = $mode === 'preview-ieof' ? $smallFile : $largeFile;
                $how = "{$transport}: {$label}";

                if ($transport === 'sync') {
                    $actual = ChildProcess::run(
                        ['-d', 'disable_functions=proc_open'],
                        $syncScript,
                        ['port' => $server->port, 'file' => $file, 'mode' => $mode],
                    );
                } else {
                    $client = new IcapClient(
                        new Config('127.0.0.1', $server->port, streamTimeout: 10.0, operationTimeout: 30.0),
                        new AsyncAmpTransport(),
                        new RequestFormatter(),
                        new ResponseParser(),
                        verdict: new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason']),
                        encapsulatedRequest: $head,
                    );
                    try {
                        $result = match ($mode) {
                            'whole' => $client->scanFile('/avscan', $file)->await(),
                            'whole-discard' => $client->scanFileDiscardingBody('/avscan', $file, 1 << 20)->await(),
                            'preview-ieof', 'preview-partial' => $client->scanFileWithPreview('/avscan', $file, 1024)->await(),
                            'preview-partial-discard' => $client->scanFileWithPreviewDiscardingBody('/avscan', $file, 1 << 20, 1024)->await(),
                        };
                        $actual = [
                            'infected' => $result->isInfected(),
                            'virus' => $result->getVirusName(),
                            'block' => $result->getBlockReason(),
                        ];
                    } catch (\Throwable $e) {
                        $actual = ['exception' => $e::class, 'code' => $e->getCode()];
                    }
                }

                expect($actual)->toBe($expected, $how)
                    ->and(array_column($server->requests, 'stage'))->toBe($expectedStages, $how);
            } finally {
                $server->stop();
            }
        }
    }
});

it('completes the full synchronous no-proc_open scanFile() flow with request head and header verdict', function () {
    $fixtures = gatewayWireFixtures();
    $queue = ['clean-204-no-encap', 'infected-200', 'blocked-200', 'error-500'];
    $index = 0;

    $server = IcapTestServer::start(static function (array $request) use ($fixtures, $queue, &$index): array {
        if (!array_key_exists('req-hdr', $request['layout'])) {
            return [$fixtures['bad-composition-418']];
        }

        return [$fixtures[$queue[$index++] ?? 'error-500']];
    });

    $payload = 'upload-payload-25-bytes!!';
    $file = FileWorkers::file($payload);

    $code = <<<'PHP'
        require $argv[1];
        $job = json_decode($argv[2], true);

        $capture = static function (Closure $call): array {
            try {
                $r = $call();
                return ['infected' => $r->isInfected(), 'virus' => $r->getVirusName(), 'block' => $r->getBlockReason()];
            } catch (Throwable $e) {
                return ['exception' => $e::class, 'code' => $e->getCode()];
            }
        };

        $config = new Ndrstmr\Icap\Config('127.0.0.1', $job['port'], socketTimeout: 2.0, streamTimeout: 5.0, operationTimeout: 8.0);
        $verdict = new Ndrstmr\Icap\VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason']);

        $withHead = new Ndrstmr\Icap\SynchronousIcapClient(new Ndrstmr\Icap\IcapClient(
            $config,
            new Ndrstmr\Icap\Transport\SynchronousStreamTransport(),
            new Ndrstmr\Icap\RequestFormatter(),
            new Ndrstmr\Icap\ResponseParser(),
            verdict: $verdict,
            encapsulatedRequest: new Ndrstmr\Icap\DTO\HttpRequest('GET', '/upload', ['Host' => ['localhost']]),
        ));
        $withoutHead = new Ndrstmr\Icap\SynchronousIcapClient(new Ndrstmr\Icap\IcapClient(
            $config,
            new Ndrstmr\Icap\Transport\SynchronousStreamTransport(),
            new Ndrstmr\Icap\RequestFormatter(),
            new Ndrstmr\Icap\ResponseParser(),
            verdict: $verdict,
        ));

        echo json_encode([
            'clean'       => $capture(static fn () => $withHead->scanFile('/avscan', $job['file'])),
            'infected'    => $capture(static fn () => $withHead->scanFile('/avscan', $job['file'])),
            'blocked'     => $capture(static fn () => $withHead->scanFile('/avscan', $job['file'])),
            'serverError' => $capture(static fn () => $withHead->scanFile('/avscan', $job['file'])),
            'noHead'      => $capture(static fn () => $withoutHead->scanFile('/avscan', $job['file'])),
            'workers'     => Ndrstmr\Icap\Tests\FileWorkers::pids(),
        ]), "\n";
        PHP;

    try {
        $run = ChildProcess::run(
            ['-d', 'disable_functions=proc_open'],
            $code,
            ['port' => $server->port, 'file' => $file],
        );
    } finally {
        $server->stop();
    }

    // Outcomes for all five gateway scenarios + zero worker processes.
    expect($run['clean'] ?? null)->toBe(['infected' => false, 'virus' => null, 'block' => null])
        ->and($run['infected'] ?? null)->toBe(['infected' => true, 'virus' => 'EICAR test file', 'block' => null])
        ->and($run['blocked'] ?? null)->toBe(['infected' => true, 'virus' => null, 'block' => 'Media type blocked'])
        ->and($run['serverError'] ?? null)->toBe(['exception' => IcapServerException::class, 'code' => 500])
        ->and($run['noHead'] ?? null)->toBe(['exception' => IcapClientException::class, 'code' => 418])
        ->and($run['workers'] ?? null)->toBe([]);

    // Exact wire bytes sent on the four scans with encapsulatedRequest: req-hdr before res-hdr, Content-Type/Length, Allow: 204, no Preview.
    $reqBlock = "GET /upload HTTP/1.1\r\nHost: localhost\r\n\r\n";
    $resBlock = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: " . strlen($payload) . "\r\n\r\n";
    $resHdrOffset = strlen($reqBlock);
    $resBodyOffset = $resHdrOffset + strlen($resBlock);
    $expectedHead = "RESPMOD icap://127.0.0.1:{$server->port}/avscan ICAP/1.0\r\n"
        . "Host: 127.0.0.1:{$server->port}\r\n"
        . "Allow: 204\r\n"
        . "Encapsulated: req-hdr=0, res-hdr={$resHdrOffset}, res-body={$resBodyOffset}\r\n"
        . "\r\n"
        . $reqBlock
        . $resBlock;

    expect(count($server->requests))->toBe(5);
    for ($i = 0; $i < 4; $i++) {
        expect($server->requests[$i]['rawHead'])->toBe($expectedHead, "request {$i}")
            ->and($server->requests[$i]['headers'])->not->toHaveKey('preview', "request {$i}")
            ->and($server->requests[$i]['bodyBytes'])->toBe(strlen($payload), "request {$i}");
    }
    expect($server->requests[4]['layout'])->not->toHaveKey('req-hdr');
});

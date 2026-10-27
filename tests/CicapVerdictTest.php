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

use Ndrstmr\Icap\CicapVerdict;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\ResponseParser;

/*
 * AP-16 (M01): the answers of the c-icap `virus_scan` service with ClamAV, recorded from the reference service
 * (tests/Fixtures/cicap-virus-scan, tests/Integration/README.md), read by the verified mode. Nothing here widens the
 * header list of the Config: the verdict knows the headers of this service.
 */

function cicapRecorded(string $name): IcapResponse
{
    return (new ResponseParser())->parse((string) file_get_contents(__DIR__ . '/Fixtures/cicap-virus-scan/' . $name . '.icap'));
}

/**
 * @param array<string, string[]> $headers
 */
function cicapAnswer(int $status, array $headers = [], ?int $encapsulated = null): IcapResponse
{
    return new IcapResponse($status, $headers, '', null, $encapsulated);
}

it('reads the recorded answers of the reference service as the service means them', function () {
    $verdict = new CicapVerdict('/avscan');

    $clean = $verdict->interpret(cicapRecorded('clean-204'), complete: true);
    expect($clean->isInfected())->toBeFalse()
        ->and($clean->getVirusName())->toBeNull();

    // The threat is the name in the headers, not the text of the structured header.
    $infected = $verdict->interpret(cicapRecorded('infected-200'), complete: true);
    expect($infected->isInfected())->toBeTrue()
        ->and($infected->getVirusName())->toBe('Eicar-Test-Signature')
        ->and($infected->getOriginalResponse()->encapsulatedStatus)->toBe(403);

    // A limit of the engine (AlertExceedsMax) and an encrypted archive (AlertEncrypted) say that the file was not scanned:
    // no finding, and no clean file.
    foreach (['limit-exceeded-200', 'encrypted-200'] as $answer) {
        expect(fn () => $verdict->interpret(cicapRecorded($answer), complete: true))->toThrow(IcapProtocolException::class, '', $answer)
            ->and(fn () => $verdict->interpret(cicapRecorded($answer), complete: false))->toThrow(IcapProtocolException::class, '', $answer);
    }
});

it('does not take an answer for a clean scan that does not show that the file was scanned', function () {
    $verdict = new CicapVerdict('/avscan');

    $cases = [
        // c-icap answers 204 at once, and without having scanned, for a type it does not scan.
        '204 before the whole file was sent' => [cicapRecorded('clean-204'), false],
        // ... and gives the message back unchanged for a type it does not scan when it may answer 204.
        'the message back, no infection signal' => [cicapRecorded('not-scanned-200'), true],
        'a block page, no infection signal' => [cicapAnswer(200, [], 403), true],
        'an error page, no infection signal' => [cicapAnswer(206, [], 500), true],
        'no message at all, no infection signal' => [cicapAnswer(200), true],
        'a status that is none of the answers to a scan' => [cicapAnswer(302), true],
    ];
    foreach ($cases as $label => [$answer, $complete]) {
        expect(fn () => $verdict->interpret($answer, $complete))->toThrow(IcapProtocolException::class, '', $label);
    }

    // The code of the exception is the status, as for the other protocol errors.
    try {
        $verdict->interpret(cicapRecorded('not-scanned-200'), complete: true);
        $thrown = null;
    } catch (IcapProtocolException $e) {
        $thrown = $e;
    }
    expect($thrown?->getCode())->toBe(200);
});

it('reads the infection headers by their values and counts, whatever the spelling of their names', function () {
    $verdict = new CicapVerdict('/avscan');
    $found = ['Type=0; Resolution=2; Threat=Eicar-Signature;'];
    $violations = ['1 - Eicar-Signature 0 0'];

    // [headers, the threat the result names]
    $cases = [
        'the c-icap pair' => [['X-Infection-Found' => $found, 'X-Violations-Found' => $violations], 'Eicar-Signature'],
        'any spelling' => [['x-infection-found' => $found, 'X-VIOLATIONS-FOUND' => $violations], 'Eicar-Signature'],
        'the infection header in lower case' => [['x-infection-found' => $found], 'Eicar-Signature'],
        'the violations header in upper case' => [['X-VIOLATIONS-FOUND' => $violations], 'Eicar-Signature'],
        'one header of the two' => [['X-Infection-Found' => $found], 'Eicar-Signature'],
        'the violations alone' => [['X-Violations-Found' => $violations], 'Eicar-Signature'],
        'several violations' => [['X-Violations-Found' => ['2 - Win.Test.One-1 0 0 - Win.Test.Two-2 1 2']], 'Win.Test.One-1'],
        'the threat of the pair is one of several' => [['X-Infection-Found' => ['Type=0; Resolution=2; Threat=Win.Test.Two-2;'], 'X-Violations-Found' => ['2 - Win.Test.One-1 0 0 - Win.Test.Two-2 0 0']], 'Win.Test.Two-2'],
        'a threat with blanks in the plain header' => [['X-Infection-Found' => ['Type=0; Resolution=2; Threat=Some Name;']], 'Some Name'],
        'no trailing semicolon' => [['X-Infection-Found' => ['Type=1; Resolution=1; Threat=Eicar-Signature']], 'Eicar-Signature'],
    ];
    foreach ($cases as $label => [$headers, $name]) {
        // The same answer is a finding as the answer to a preview and as the final one, whatever follows it.
        foreach ([true, false] as $complete) {
            foreach ([200, 206] as $status) {
                $result = $verdict->interpret(cicapAnswer($status, $headers, 403), $complete);
                expect($result->isInfected())->toBeTrue("{$label}, {$status}")
                    ->and($result->getVirusName())->toBe($name, "{$label}, {$status}");
            }
        }
    }

    // No violations is no finding: a 204 stays a clean answer and a 200 stays an answer without a signal.
    expect($verdict->interpret(cicapAnswer(204, ['X-Violations-Found' => ['0']]), complete: true)->isInfected())->toBeFalse()
        ->and(fn () => $verdict->interpret(cicapAnswer(200, ['X-Violations-Found' => ['0']]), complete: true))->toThrow(IcapProtocolException::class);
});

it('refuses infection headers that contradict each other, the status or themselves', function () {
    $verdict = new CicapVerdict('/avscan');
    $found = 'Type=0; Resolution=2; Threat=Eicar-Signature;';

    $cases = [
        '204 with an infection' => [204, ['X-Infection-Found' => [$found]]],
        '204 with violations' => [204, ['X-Violations-Found' => ['1 - Eicar-Signature 0 0']]],
        'infection, no violations' => [200, ['X-Infection-Found' => [$found], 'X-Violations-Found' => ['0']]],
        'another threat in the violations' => [200, ['X-Infection-Found' => [$found], 'X-Violations-Found' => ['1 - Other-Signature 0 0']]],
        'more violations than listed' => [200, ['X-Violations-Found' => ['2 - Eicar-Signature 0 0']]],
        'more listed than counted' => [200, ['X-Violations-Found' => ['1 - Eicar-Signature 0 0 - Other 0 0']]],
        'a count that is none' => [200, ['X-Violations-Found' => ['many - Eicar-Signature 0 0']]],
        'no count' => [200, ['X-Violations-Found' => ['-']]],
        'a problem id that is none' => [200, ['X-Violations-Found' => ['1 - Eicar-Signature x 0']]],
        'a type that is none' => [200, ['X-Infection-Found' => ['Type=x; Resolution=2; Threat=Eicar-Signature;']]],
        'no threat' => [200, ['X-Infection-Found' => ['Type=0; Resolution=2; Threat=;']]],
        'the structure of another vendor' => [200, ['X-Infection-Found' => ['Eicar-Signature']]],
    ];
    foreach ($cases as $label => [$status, $headers]) {
        foreach ([true, false] as $complete) {
            expect(fn () => $verdict->interpret(cicapAnswer($status, $headers, 403), $complete))
                ->toThrow(IcapProtocolException::class, '', $label);
        }
    }
});

it('names no part of the response in what it throws', function () {
    $verdict = new CicapVerdict('/avscan');
    $secret = 'Threat=not-for-the-logs-' . bin2hex(random_bytes(4));

    foreach ([
        cicapAnswer(200, ['X-Infection-Found' => ['Type=x; Resolution=2; ' . $secret]], 403),
        cicapAnswer(204, ['X-Infection-Found' => ['Type=0; Resolution=2; ' . $secret . ';']]),
    ] as $answer) {
        try {
            $verdict->interpret($answer, complete: true);
            $message = '';
        } catch (IcapProtocolException $e) {
            $message = $e->getMessage();
        }
        expect($message)->not->toBe('')->and($message)->not->toContain('not-for-the-logs');
    }
});

it('is made for one service, which has to be a service of the form the client sends', function () {
    expect((new CicapVerdict('/avscan'))->service)->toBe('/avscan')
        ->and((new CicapVerdict('/avscan?mode=simple'))->service)->toBe('/avscan?mode=simple');

    foreach (['', 'avscan', '/avscan#fragment', "/avscan\r\nHost: evil", '/av scan', 'icap://localhost/avscan'] as $service) {
        expect(fn () => new CicapVerdict($service))->toThrow(\InvalidArgumentException::class, '', var_export($service, true));
    }
});

it('does not take what the engine says about its limits and encrypted files for an infection, and keeps every finding', function () {
    $verdict = new CicapVerdict('/avscan');
    // ClamAV 1.4.3 (clamd.conf: AlertExceedsMax, AlertEncrypted) says so when it did not look into the file.
    $diagnostics = [
        'Heuristics.Limits.Exceeded', 'Heuristics.Limits.Exceeded.MaxFileSize', 'Heuristics.Limits.Exceeded.MaxScanSize',
        'Heuristics.Limits.Exceeded.MaxRecursion', 'Heuristics.Limits.Exceeded.MaxFiles',
        'Heuristics.Encrypted.Zip', 'Heuristics.Encrypted.7Zip', 'Heuristics.Encrypted.RAR', 'Heuristics.Encrypted.PDF', 'Heuristics.Encrypted.EGG',
    ];
    // Signatures and the other heuristics of ClamAV are findings; so is a name that only has the words in it.
    $findings = [
        'Eicar-Signature', 'Win.Test.EICAR_HDB-1', 'Heuristics.Broken.Executable', 'Heuristics.OLE2.ContainsMacros.VBA',
        'Heuristics.Phishing.Email.SpoofedDomain', 'Heuristics.Structured.SSN',
        'Win.Trojan.Heuristics.Encrypted.Zip', 'Win.Trojan.Heuristics.Limits.Exceeded.MaxFileSize',
    ];

    /** @param list<string> $names */
    $shapes = static function (array $names): array {
        $pair = [
            'X-Infection-Found' => ['Type=0; Resolution=2; Threat=' . $names[0] . ';'],
            'X-Violations-Found' => [count($names) . ' ' . implode(' ', array_map(static fn (string $n): string => "- {$n} 0 0", $names))],
        ];

        return ['the pair of c-icap' => $pair, 'the infection header' => ['X-Infection-Found' => $pair['X-Infection-Found']], 'the violations' => ['X-Violations-Found' => $pair['X-Violations-Found']]];
    };

    foreach ($diagnostics as $name) {
        foreach ($shapes([$name]) as $shape => $headers) {
            foreach ([200, 206] as $status) {
                foreach ([true, false] as $complete) {
                    expect(fn () => $verdict->interpret(cicapAnswer($status, $headers, 403), $complete))
                        ->toThrow(IcapProtocolException::class, '', "{$name}, {$shape}, {$status}");
                }
            }
        }
    }
    foreach ($findings as $name) {
        foreach ($shapes([$name]) as $shape => $headers) {
            $result = $verdict->interpret(cicapAnswer(200, $headers, 403), complete: true);
            expect($result->isInfected())->toBeTrue("{$name}, {$shape}")
                ->and($result->getVirusName())->toBe($name, "{$name}, {$shape}");
        }
    }

    // A finding next to a diagnostic is a finding, whichever the service lists first. The infection header names only the
    // first of them, the violations list both.
    foreach ([['Heuristics.Encrypted.Zip', 'Eicar-Signature'], ['Eicar-Signature', 'Heuristics.Limits.Exceeded.MaxFileSize']] as $names) {
        foreach ($shapes($names) as $shape => $headers) {
            if ($shape === 'the infection header') {
                continue;
            }
            $result = $verdict->interpret(cicapAnswer(200, $headers, 403), complete: false);
            expect($result->isInfected())->toBeTrue(implode(', ', $names) . ", {$shape}")
                ->and($result->getVirusName())->toBe('Eicar-Signature', implode(', ', $names) . ", {$shape}");
        }
    }
});

it('lets a 100 Continue ask for the rest of the file, and takes it for no answer when it reports an infection', function () {
    $verdict = new CicapVerdict('/avscan');

    expect(fn () => $verdict->assertContinuation(cicapRecorded('continue-100')))->not->toThrow(IcapProtocolException::class)
        ->and(fn () => $verdict->assertContinuation(cicapAnswer(100, ['ISTag' => ['"x"'], 'X-Violations-Found' => ['0']])))->not->toThrow(IcapProtocolException::class);

    $found = 'Type=0; Resolution=2; Threat=Eicar-Test-Signature;';
    $violations = '1 - Eicar-Test-Signature 0 0';
    $cases = [
        'an infection' => ['X-Infection-Found' => [$found]],
        'violations' => ['X-Violations-Found' => [$violations]],
        'the pair of c-icap' => ['X-Infection-Found' => [$found], 'X-Violations-Found' => [$violations]],
        'any spelling' => ['x-infection-found' => [$found], 'X-VIOLATIONS-FOUND' => [$violations]],
        'a diagnostic of the engine' => ['X-Infection-Found' => ['Type=0; Resolution=2; Threat=Heuristics.Encrypted.Zip;']],
        'an infection header that is malformed' => ['X-Infection-Found' => ['not what c-icap sends']],
        'violations that are malformed' => ['X-Violations-Found' => ['many']],
        'headers that contradict each other' => ['X-Infection-Found' => [$found], 'X-Violations-Found' => ['0']],
    ];
    foreach ($cases as $label => $headers) {
        try {
            $verdict->assertContinuation(cicapAnswer(100, $headers));
            $thrown = null;
        } catch (IcapProtocolException $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeInstanceOf(IcapProtocolException::class, $label)
            ->and($thrown?->getCode())->toBe(100, $label);
    }
});

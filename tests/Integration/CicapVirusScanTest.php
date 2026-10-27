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
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Tests\FileWorkers;
use Ndrstmr\Icap\Tests\OneRequestTransport;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * AP-16 (M01): the verified mode against the reference service (docker-compose.yml, tests/Integration/README.md):
 * c-icap `virus_scan` with ClamAV in the reference configuration. ICAP_CLAMAV_SERVICE is its /avscan, which scans
 * every type, and the verdict is made for it; ICAP_TYPED_SERVICE is /avscan_typed, which scans only the types the
 * unmodified image scans, and is read at the protocol level only. The Config is the default one: no header list is
 * widened. A test without its service skips, and a skip is no result.
 *
 * The EICAR test string is built from pieces so that this file does not trigger virus scanners itself.
 */

afterEach(function (): void {
    FileWorkers::removeFiles();
});

function cicapLiveEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && trim($value) !== '' ? $value : null;
}

/**
 * @return array{host: string, port: int, service: string}
 */
function cicapLiveService(string $variable = 'ICAP_CLAMAV_SERVICE'): array
{
    $host = cicapLiveEnv('ICAP_HOST');
    $service = cicapLiveEnv($variable);
    if ($host === null || $service === null) {
        // @phpstan-ignore-next-line — test() resolves to PHPUnit at runtime.
        test()->markTestSkipped("Integration env not configured: ICAP_HOST and {$variable} are needed.");
    }
    assert($host !== null && $service !== null);

    return ['host' => $host, 'port' => (int) (cicapLiveEnv('ICAP_PORT') ?? '1344'), 'service' => $service];
}

function cicapLiveClient(string $host, int $port, string $service, bool $sessions = true): IcapClient
{
    return new IcapClient(
        new Config($host, $port, streamTimeout: 60.0, operationTimeout: 120.0),
        $sessions ? new AsyncAmpTransport() : new OneRequestTransport(),
        new RequestFormatter(),
        new ResponseParser(),
        verdict: new CicapVerdict($service),
    );
}

function cicapEicar(): string
{
    return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
}

/**
 * A PDF whose only content is the EICAR string as a stream; a type the unmodified image does not scan. $pad lengthens it
 * with comment lines, so that it is more than a preview of 1024 bytes.
 */
function cicapPdfWithEicar(int $pad = 0): string
{
    $eicar = cicapEicar();

    return "%PDF-1.4\n"
        . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] /Contents 4 0 R >>\nendobj\n"
        . "4 0 obj\n<< /Length " . strlen($eicar) . " >>\nstream\n" . $eicar . "\nendstream\nendobj\n"
        . str_repeat("% padding\n", intdiv($pad, 10))
        . "trailer\n<< /Root 1 0 R /Size 5 >>\n%%EOF\n";
}

/**
 * A ZIP archive that stores its members as they are.
 *
 * @param array<string, string> $members name => content
 */
function cicapZip(array $members): string
{
    $files = '';
    $directory = '';
    foreach ($members as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, strlen($files)) . $name;
        $files .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0) . $name . $data;
    }

    return $files . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($members), count($members), strlen($directory), strlen($files), 0);
}

/**
 * The scan helpers of the client, each with a preview of 1024 bytes where it has one.
 *
 * @return array<string, \Closure(IcapClient, string, string): ScanResult>
 */
function cicapLiveHelpers(): array
{
    return [
        'scanFile' => static fn (IcapClient $c, string $service, string $path): ScanResult => $c->scanFile($service, $path)->await(),
        'scanFileDiscardingBody' => static fn (IcapClient $c, string $service, string $path): ScanResult => $c->scanFileDiscardingBody($service, $path, 1 << 20)->await(),
        'scanFileWithPreview' => static fn (IcapClient $c, string $service, string $path): ScanResult => $c->scanFileWithPreview($service, $path, 1024)->await(),
        'scanFileWithPreviewDiscardingBody' => static fn (IcapClient $c, string $service, string $path): ScanResult => $c->scanFileWithPreviewDiscardingBody($service, $path, 1 << 20, 1024)->await(),
    ];
}

it('finds EICAR with the default Config and the default factory, which the default mode cannot', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $eicar = FileWorkers::file(cicapEicar());
    $clean = FileWorkers::file("Lorem ipsum dolor sit amet.\n");

    // The factory with its default wiring: the Config has the default header list (X-Virus-Name), nothing is widened.
    $verified = IcapClient::forServer($host, $port, new CicapVerdict($service));
    $found = $verified->scanFile($service, $eicar)->await();
    expect($found->isInfected())->toBeTrue()
        ->and($found->getVirusName())->toMatch('/^Eicar-(Test-)?Signature$/')
        ->and($verified->scanFile($service, $clean)->await()->isInfected())->toBeFalse();

    // The same service, the same file, the default mode: the infection headers of c-icap are not on the list.
    $default = IcapClient::forServer($host, $port);
    expect($default->scanFile($service, $eicar)->await()->isInfected())->toBeFalse();
});

it('gives the same verdict through every helper and both preview flows, with the infection in and beyond the preview', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $filler = str_repeat('0123456789abcdef', 192);
    // [content, the verdict: a name (matched as a pattern) or null for clean]
    $samples = [
        'an empty file' => ['', null],
        'a harmless file inside the preview' => [str_repeat('a', 500), null],
        'a harmless file beyond the preview' => [str_repeat('a', 5000), null],
        'EICAR' => [cicapEicar(), '/^Eicar-(Test-)?Signature$/'],
        'EICAR first in an archive that is more than the preview' => [cicapZip(['eicar.com' => cicapEicar(), 'filler.txt' => $filler]), '/^Eicar-(Test-)?Signature$/'],
        'EICAR beyond the preview of an archive' => [cicapZip(['filler.txt' => $filler, 'eicar.com' => cicapEicar()]), '/^Eicar-(Test-)?Signature$/'],
    ];

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $flow => $sessions) {
        $client = cicapLiveClient($host, $port, $service, $sessions);
        foreach ($samples as $label => [$content, $threat]) {
            $path = FileWorkers::file($content);
            foreach (cicapLiveHelpers() as $helper => $scan) {
                $result = $scan($client, $service, $path);
                $how = "{$flow}, {$helper}: {$label}";

                expect($result->isInfected())->toBe($threat !== null, $how);
                if ($threat === null) {
                    expect($result->getVirusName())->toBeNull($how);
                } else {
                    expect($result->getVirusName())->toMatch($threat, $how);
                }
            }
        }
    }
});

it('decides at the edges of the preview as it does elsewhere', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $threat = '/^Eicar-(Test-)?Signature$/';
    // An archive with EICAR that is exactly $size bytes long.
    $archive = static function (int $size): string {
        $empty = strlen(cicapZip(['eicar.com' => cicapEicar(), 'filler.bin' => '']));

        return cicapZip(['eicar.com' => cicapEicar(), 'filler.bin' => str_repeat('0', $size - $empty)]);
    };

    foreach (['one socket for preview and rest' => true, 'one request per message' => false] as $flow => $sessions) {
        $client = cicapLiveClient($host, $port, $service, $sessions);
        // [size of the file, preview]: the preview is one byte short of the file, holds it exactly, is larger, or is empty.
        foreach ([[1025, 1024], [1024, 1024], [1023, 1024], [1025, 0], [400, 0]] as [$size, $preview]) {
            foreach (['harmless' => [str_repeat('a', $size), null], 'EICAR' => [$archive($size), $threat]] as $kind => [$content, $expected]) {
                $path = FileWorkers::file($content);
                expect(strlen($content))->toBe($size);

                foreach ([
                    'buffering' => $client->scanFileWithPreview($service, $path, $preview)->await(),
                    'discarding' => $client->scanFileWithPreviewDiscardingBody($service, $path, 1 << 20, $preview)->await(),
                ] as $helper => $result) {
                    $how = "{$flow}, {$helper}, {$kind} of {$size} bytes, preview {$preview}";
                    expect($result->isInfected())->toBe($expected !== null, $how);
                    if ($expected !== null) {
                        expect($result->getVirusName())->toMatch($expected, $how);
                    }
                }
            }
        }
    }
});

it('finds EICAR in a type that the unmodified image does not scan', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $client = cicapLiveClient($host, $port, $service);

    // The reference configuration scans every type (force=on): EICAR in a PDF, inside the preview and beyond it.
    foreach (['EICAR in a PDF inside the preview' => cicapPdfWithEicar(), 'EICAR in a PDF beyond the preview' => cicapPdfWithEicar(2000)] as $label => $content) {
        $path = FileWorkers::file($content);
        foreach (cicapLiveHelpers() as $helper => $scan) {
            $result = $scan($client, $service, $path);

            expect($result->isInfected())->toBeTrue("{$helper}: {$label}")
                ->and($result->getVirusName())->toMatch('/^Eicar-(Test-)?Signature$/', "{$helper}: {$label}");
        }
    }
});

it('reports what the engine did not look into as an error, neither as an infection nor as a clean file', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $client = cicapLiveClient($host, $port, $service);

    // AlertExceedsMax and MaxFileSize 2M in the reference configuration, AlertEncrypted: the engine reports a limit and an
    // encrypted archive with a name of its own, which is no finding. The files are harmless, or the engine could not know.
    $text = str_repeat("Lorem ipsum dolor sit amet.\n", intdiv(3 * 1024 * 1024, 28));
    $samples = [
        'a harmless text over the size limit of the engine' => $text,
        'an archive over the size limit of the engine' => cicapZip(['eicar.com' => cicapEicar(), 'filler.bin' => str_repeat("\0", 3 * 1024 * 1024)]),
        'an encrypted archive' => (string) file_get_contents(__DIR__ . '/../Fixtures/cicap-virus-scan/encrypted-eicar.zip'),
    ];
    foreach ($samples as $label => $content) {
        $path = FileWorkers::file($content);
        foreach (cicapLiveHelpers() as $helper => $scan) {
            expect(fn () => $scan($client, $service, $path))->toThrow(IcapProtocolException::class, '', "{$helper}: {$label}");
        }
    }

    // Under the limit the same text is scanned, and clean.
    $path = FileWorkers::file(substr($text, 0, 1024 * 1024));
    foreach (cicapLiveHelpers() as $helper => $scan) {
        expect($scan($client, $service, $path)->isInfected())->toBeFalse($helper);
    }
});

it('decides request() by what the request sent', function () {
    ['host' => $host, 'port' => $port, 'service' => $service] = cicapLiveService();
    $client = cicapLiveClient($host, $port, $service);
    // $body is what goes out; the message announces $length bytes, the whole of it unless it is the start of a longer one.
    $request = static fn (string $body, array $headers, bool $ieof = false, ?int $length = null): IcapRequest => new IcapRequest(
        'RESPMOD',
        "icap://{$host}:{$port}{$service}",
        $headers,
        encapsulatedResponse: new HttpResponse(statusCode: 200, headers: ['Content-Length' => [(string) ($length ?? strlen($body))]], body: $body),
        previewIsComplete: $ieof,
    );
    $harmless = str_repeat('a', 500);

    // The whole message, with or without a preview that ends in ieof.
    foreach (['without a preview' => [[], false], 'with a preview that ends in ieof' => [['Preview' => '1024'], true]] as $label => [$headers, $ieof]) {
        $headers += ['Allow' => '204'];
        expect($client->request($request(cicapEicar(), $headers, $ieof))->await()->isInfected())->toBeTrue($label)
            ->and($client->request($request($harmless, $headers, $ieof))->await()->isInfected())->toBeFalse($label);
    }

    // The start of a message: the service asks for the rest with 100 Continue, which request() does not send.
    expect(fn () => $client->request($request(substr($harmless, 0, 10), ['Preview' => '10', 'Allow' => '204'], false, 500))->await())->toThrow(IcapProtocolException::class);
});

it('is made for one service, and the answers of a service that skips a type cannot tell it', function () {
    ['host' => $host, 'port' => $port, 'service' => $everything] = cicapLiveService();
    ['service' => $typed] = cicapLiveService('ICAP_TYPED_SERVICE');
    $big = FileWorkers::file(cicapPdfWithEicar(2000));
    $small = FileWorkers::file(cicapPdfWithEicar());
    $harmless = FileWorkers::file(str_repeat('a', 500));

    // The verified mode scans with its service only, so it does not take the answers of the other one for a scan.
    $verified = IcapClient::forServer($host, $port, new CicapVerdict($everything));
    foreach ([$big, $small, $harmless] as $path) {
        expect(fn () => $verified->scanFile($typed, $path))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $verified->scanFileDiscardingBody($typed, $path, 1 << 20))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $verified->scanFileWithPreview($typed, $path, 1024))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $verified->scanFileWithPreviewDiscardingBody($typed, $path, 1 << 20, 1024))->toThrow(\InvalidArgumentException::class);
    }

    // The protocol, as the default mode returns it: the status of the answer is what the service said. To the preview of a
    // file that is not all sent, a service that scans the type asks for the rest and then reports the infection (200), and
    // one that skips it answers 204 at once. A file that the preview holds ends as 204 on the one that skips the type,
    // exactly as a clean file does.
    $raw = IcapClient::forServer($host, $port);
    $answer = static fn (string $service, string $path): \Ndrstmr\Icap\DTO\IcapResponse => $raw->scanFileWithPreview($service, $path, 1024)->await()->getOriginalResponse();

    expect($answer($everything, $big)->statusCode)->toBe(200)
        ->and($answer($typed, $big)->statusCode)->toBe(204)
        ->and($answer($everything, $small)->statusCode)->toBe(200)
        ->and($answer($typed, $small)->statusCode)->toBe(204)
        ->and($answer($typed, $harmless)->statusCode)->toBe(204);
    expect($answer($everything, $small)->headers)->toHaveKey('X-Infection-Found')
        ->and($answer($typed, $small)->headers)->not->toHaveKey('X-Infection-Found');
});

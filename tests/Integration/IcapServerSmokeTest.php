<?php

declare(strict_types=1);

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\Tests\AsyncTestCase;

uses(AsyncTestCase::class);

/**
 * Smoke-test suite against a real ICAP server.
 *
 * Controlled via the ICAP_HOST / ICAP_PORT / ICAP_ECHO_SERVICE /
 * ICAP_CLAMAV_SERVICE environment variables. Tests skip themselves
 * when the env isn't configured so contributors without Docker get a
 * green `composer test:integration` — CI flips the env on and gets
 * real wire-level verification.
 */

function integrationEnv(string $name): ?string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return $value;
}

function skipUnlessIntegration(?string ...$required): void
{
    foreach ($required as $value) {
        if ($value === null) {
            // @phpstan-ignore-next-line — test() resolves to PHPUnit at runtime.
            test()->markTestSkipped('Integration env not configured — ICAP server not reachable.');
        }
    }
}

it('performs an OPTIONS round-trip against the configured echo service', function () {
    $host = integrationEnv('ICAP_HOST');
    $service = integrationEnv('ICAP_ECHO_SERVICE');
    skipUnlessIntegration($host, $service);

    assert($host !== null);
    assert($service !== null);
    $port = (int) (integrationEnv('ICAP_PORT') ?? '1344');

    $config = new Config($host, $port);
    $client = new IcapClient(
        $config,
        new \Ndrstmr\Icap\Transport\AsyncAmpTransport(),
        new \Ndrstmr\Icap\RequestFormatter(),
        new \Ndrstmr\Icap\ResponseParser(),
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $service) {
        $result = $client->options($service)->await();
        expect($result)->toBeInstanceOf(ScanResult::class)
            ->and($result->getOriginalResponse()->statusCode)->toBe(200);
    });
});

it('scans the EICAR test file against the ClamAV-backed service', function () {
    $host = integrationEnv('ICAP_HOST');
    $service = integrationEnv('ICAP_CLAMAV_SERVICE');
    skipUnlessIntegration($host, $service);

    assert($host !== null);
    assert($service !== null);
    $port = (int) (integrationEnv('ICAP_PORT') ?? '1344');

    $eicar = sys_get_temp_dir() . '/icap-flow-eicar-' . uniqid('', true) . '.com';
    // The EICAR anti-virus test string — every signature-based scanner
    // recognises it. Building it in pieces so this test file itself
    // doesn't trigger AV alarms on contributor machines.
    file_put_contents(
        $eicar,
        'X5O!P%@AP[4\PZX54(P^)7CC)7}$'
        . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
    );

    try {
        // c-icap reports infections in X-Violations-Found, not the
        // ClamAV/Squid-style X-Virus-Name. Configure the multi-vendor
        // virus-header list so the client recognises whichever header
        // the server actually sends.
        $config = (new Config($host, $port))->withVirusFoundHeaders([
            'X-Virus-Name',
            'X-Infection-Found',
            'X-Violations-Found',
            'X-Virus-ID',
        ]);
        $client = new IcapClient(
            $config,
            new \Ndrstmr\Icap\Transport\AsyncAmpTransport(),
            new \Ndrstmr\Icap\RequestFormatter(),
            new \Ndrstmr\Icap\ResponseParser(),
        );

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $service, $eicar) {
            $result = $client->scanFile($service, $eicar)->await();
            expect($result->isInfected())->toBeTrue()
                ->and($result->getVirusName())->not->toBeNull();
        });
    } finally {
        if (file_exists($eicar)) {
            unlink($eicar);
        }
    }
});

it('returns a clean verdict for a harmless text file', function () {
    $host = integrationEnv('ICAP_HOST');
    $service = integrationEnv('ICAP_CLAMAV_SERVICE');
    skipUnlessIntegration($host, $service);

    assert($host !== null);
    assert($service !== null);
    $port = (int) (integrationEnv('ICAP_PORT') ?? '1344');

    $clean = sys_get_temp_dir() . '/icap-flow-clean-' . uniqid('', true) . '.txt';
    file_put_contents($clean, "Lorem ipsum dolor sit amet.\n");

    try {
        $config = (new Config($host, $port))->withVirusFoundHeaders([
            'X-Virus-Name',
            'X-Infection-Found',
            'X-Violations-Found',
            'X-Virus-ID',
        ]);
        $client = new IcapClient(
            $config,
            new \Ndrstmr\Icap\Transport\AsyncAmpTransport(),
            new \Ndrstmr\Icap\RequestFormatter(),
            new \Ndrstmr\Icap\ResponseParser(),
        );

        /** @var AsyncTestCase $this */
        $this->runAsyncTest(function () use ($client, $service, $clean) {
            $result = $client->scanFile($service, $clean)->await();
            expect($result->isInfected())->toBeFalse();
        });
    } finally {
        if (file_exists($clean)) {
            unlink($clean);
        }
    }
});

<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Amp\DeferredCancellation;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * Cancellation — abort an in-flight ICAP scan from the outside.
 *
 * Two common scenarios:
 *   1. Timeout-based: kill the scan after N seconds regardless of
 *      what the server is doing (e.g. for upload APIs with SLAs).
 *   2. User-initiated: the HTTP client cancelled the upload, so
 *      there's no point continuing the scan.
 *
 * Both use amphp's Cancellation hierarchy. The library combines the
 * user-supplied Cancellation with its own per-IO TimeoutCancellation
 * via CompositeCancellation — whichever fires first wins.
 */

$config = new Config('icap.example.com');
$client = new IcapClient(
    $config,
    new AsyncAmpTransport(new AmpConnectionPool()),
    new RequestFormatter(),
    new ResponseParser(),
);

// --- Scenario 1: Hard timeout ---

\Amp\async(function () use ($client) {
    $timeout = new TimeoutCancellation(5.0); // 5 seconds max

    try {
        $result = $client->scanFile(
            '/avscan',
            __DIR__ . '/../eicar.com',
            cancellation: $timeout,
        )->await();

        echo $result->isInfected()
            ? 'Virus: ' . $result->getVirusName() . PHP_EOL
            : 'Clean' . PHP_EOL;
    } catch (\Amp\CancelledException) {
        echo 'Scan timed out after 5 seconds' . PHP_EOL;
    }
});

// --- Scenario 2: User-initiated cancellation ---

\Amp\async(function () use ($client) {
    $deferred = new DeferredCancellation();

    $scanFuture = $client->scanFile(
        '/avscan',
        __DIR__ . '/../eicar.com',
        cancellation: $deferred->getCancellation(),
    );

    // Simulate: user cancels the upload after 2 seconds.
    \Amp\delay(2.0);
    $deferred->cancel();

    try {
        $result = $scanFuture->await();
        echo $result->isInfected() ? 'Infected' : 'Clean';
        echo PHP_EOL;
    } catch (\Amp\CancelledException) {
        echo 'Scan cancelled by user' . PHP_EOL;
    }
});

\Revolt\EventLoop::run();

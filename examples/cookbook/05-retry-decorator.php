<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RetryingIcapClient;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * RetryingIcapClient — automatic exponential-backoff retries of OPTIONS
 * after a transient ICAP 5xx server error.
 *
 * The decorator wraps any IcapClientInterface and repeats options() on
 * IcapServerException (503 Service Unavailable, 500 Internal Error, etc.):
 * a bodyless OPTIONS is safe to send again. Nothing else is repeated —
 * a 5xx does not prove that the server did not process the bytes of an
 * upload — so request(), scanFile() and scanFileWithPreview() get one
 * attempt. Client errors (4xx) are NOT retried either — those indicate a
 * caller mistake.
 *
 * The pauses are a random share (jitter) of the exponential delay, and
 * the whole call — every attempt and pause — has one end: operationTimeout.
 * A cancellation ends a pause at once.
 *
 * Typical use case: ClamAV reloading signatures (returns 503 for a few
 * seconds) when the application asks for the server's capabilities, or a
 * vendor appliance under heavy load.
 */

$config = new Config('icap.example.com');

$inner = new IcapClient(
    $config,
    new AsyncAmpTransport(new AmpConnectionPool()),
    new RequestFormatter(),
    new ResponseParser(),
);

$client = new RetryingIcapClient(
    inner: $inner,
    maxAttempts: 3,              // give up after 3 tries (the first one counts)
    baseDelaySeconds: 0.5,       // the first pause is up to 0.5 s ...
    backoffFactor: 2.0,          // ... then up to 1.0 → 2.0 s
    maxDelaySeconds: 5.0,        // cap at 5 s between retries
    operationTimeout: 30.0,      // attempts and pauses together: at most 30 s
);

// Use $client exactly like a regular IcapClient:
\Amp\async(function () use ($client) {
    $options = $client->options('/avscan')->await();   // repeated after a 5xx
    echo 'Preview: ' . ($options->headers['Preview'][0] ?? 'not advertised') . PHP_EOL;

    $result = $client->scanFile('/avscan', __DIR__ . '/../eicar.com')->await();   // one attempt

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();

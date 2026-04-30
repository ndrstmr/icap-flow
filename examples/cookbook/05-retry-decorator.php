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
 * RetryingIcapClient — automatic exponential-backoff retries for
 * transient ICAP 5xx server errors.
 *
 * The decorator wraps any IcapClientInterface and replays the request
 * on IcapServerException (503 Service Unavailable, 500 Internal Error,
 * etc.). Client errors (4xx) are NOT retried — those indicate a caller
 * mistake.
 *
 * Typical use case: ClamAV reloading signatures (returns 503 for a
 * few seconds), brief network blips behind a load balancer, or a
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
    maxAttempts: 3,              // give up after 3 tries
    baseDelaySeconds: 0.5,       // first retry after 0.5 s
    backoffMultiplier: 2.0,      // 0.5 → 1.0 → 2.0 s
    maxDelaySeconds: 5.0,        // cap at 5 s between retries
);

// Use $client exactly like a regular IcapClient:
\Amp\async(function () use ($client) {
    $result = $client->scanFile('/avscan', __DIR__ . '/../eicar.com')->await();

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();

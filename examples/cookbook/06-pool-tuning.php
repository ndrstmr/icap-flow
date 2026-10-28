<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * Connection-pool tuning — configure the connection limits, idle socket
 * eviction and server-side Max-Connections awareness.
 *
 * Important for long-running workers (RoadRunner, Swoole, ReactPHP)
 * that keep connections alive across requests. The pool counts the
 * connections being set up, in use and idle against its limits, closes
 * stale idle sockets by itself and respects the server's connection
 * budget. A call that finds the pool full fails with an
 * IcapConnectionException, unless a queue is configured. A socket goes
 * back to the pool only after the validated exchange of
 * scanFileWithPreview(); scanFile() and request() close theirs.
 */

$pool = new AmpConnectionPool(
    maxConnectionsPerHost: 8,     // most connections to one host:port, in use and idle together
    maxIdleSeconds: 30.0,         // close sockets idle longer than 30 s
    // maxWaiters: 16,            // optional queue: up to 16 calls wait ...
    // waitTimeout: 5.0,          // ... for at most 5 s each, first come, first served
);

// Optional: inform the pool about the server's Max-Connections header.
// Typically done after the first OPTIONS round trip.
$cache = new InMemoryOptionsCache();

$config = new Config('icap.example.com');

$client = new IcapClient(
    $config,
    new AsyncAmpTransport($pool),
    new RequestFormatter(),
    new ResponseParser(),
    optionsCache: $cache,
);

\Amp\async(function () use ($client, $pool, $config) {
    // The first OPTIONS response tells us the server's connection limit.
    $options = $client->options('/avscan')->await();
    $headers = $options->headers;

    // Tune the pool to respect the server's advertised limit. The config says
    // which endpoint the limit belongs to; without it the pool lowers every endpoint.
    $pool->tuneFromOptions($options, $config);

    echo 'Max-Connections: ' . ($headers['Max-Connections'][0] ?? 'not advertised') . PHP_EOL;

    // Now scan — the pool reuses the socket of a validated preview exchange,
    // closes stale ones, and caps at min(localCap, serverMax).
    $result = $client->scanFileWithPreview('/avscan', __DIR__ . '/../eicar.com')->await();

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();

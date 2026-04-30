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
 * Connection-pool tuning — configure idle socket eviction, per-host
 * connection caps, and server-side Max-Connections awareness.
 *
 * Important for long-running workers (RoadRunner, Swoole, ReactPHP)
 * that keep connections alive across requests. Without tuning, idle
 * sockets accumulate; with it, the pool automatically evicts stale
 * entries and respects the server's connection budget.
 */

$pool = new AmpConnectionPool(
    maxConnectionsPerHost: 8,     // local idle-socket cap (per host:port:tls)
    maxIdleSeconds: 30.0,         // evict sockets idle longer than 30 s
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

\Amp\async(function () use ($client, $pool) {
    // The first OPTIONS response tells us the server's connection limit.
    $options = $client->options('/avscan')->await();
    $headers = $options->getOriginalResponse()->headers;

    // Tune the pool to respect the server's advertised limit.
    $pool->tuneFromOptions($options->getOriginalResponse());

    echo 'Max-Connections: ' . ($headers['Max-Connections'][0] ?? 'not advertised') . PHP_EOL;

    // Now scan — the pool will reuse sockets, evict stale ones,
    // and cap at min(localCap, serverMax).
    $result = $client->scanFile('/avscan', __DIR__ . '/../eicar.com')->await();

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();

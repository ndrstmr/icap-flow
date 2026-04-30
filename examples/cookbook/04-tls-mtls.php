<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

/*
 * TLS / mTLS — connect to an ICAP server that requires encrypted
 * transport (icaps://). Most enterprise appliances (Symantec, Trend
 * Micro, Sophos) expose their ICAP interface on port 11344 with TLS.
 *
 * For mTLS (mutual TLS) the client presents its own certificate,
 * which the appliance verifies against a trusted CA. This is typical
 * in zero-trust or BSI-Grundschutz-aligned environments.
 */

// --- Plain TLS (server-only verification) ---

$config = (new Config(
    host: 'icap.example.com',
    port: 11344,
))
    ->withTlsContext(
        (new ClientTlsContext('icap.example.com'))
            ->withCaFile('/etc/ssl/certs/ca-certificates.crt'),
    );

$client = new IcapClient(
    $config,
    new AsyncAmpTransport(new AmpConnectionPool()),
    new RequestFormatter(),
    new ResponseParser(),
);

// --- mTLS (client certificate) ---

$mtlsConfig = (new Config(
    host: 'icap-secure.internal',
    port: 11344,
))
    ->withTlsContext(
        (new ClientTlsContext('icap-secure.internal'))
            ->withCaFile('/etc/pki/tls/certs/corporate-ca.pem')
            ->withCertificate(new \Amp\Socket\Certificate(
                '/etc/pki/tls/certs/client.pem',
                '/etc/pki/tls/private/client.key',
            )),
    );

$mtlsClient = new IcapClient(
    $mtlsConfig,
    new AsyncAmpTransport(new AmpConnectionPool()),
    new RequestFormatter(),
    new ResponseParser(),
);

// Both clients work identically from here:
\Amp\async(function () use ($client) {
    $result = $client->scanFile('/avscan', __DIR__ . '/../eicar.com')->await();
    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();

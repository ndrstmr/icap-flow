<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpRequest;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\VirusHeaderVerdict;

/*
 * Fail-closed header verdict (docs/virus-header-verdict.md) combined with a
 * fixed encapsulated HTTP request head (docs/file-sources.md) and synchronous
 * whole-file scanning (no preview, no worker processes).
 *
 * VirusHeaderVerdict is not verified against a reference service: verify your
 * target ICAP server against the checklist in docs/virus-header-verdict.md.
 */

$config = new Config('127.0.0.1', 1344);
$client = new SynchronousIcapClient(new IcapClient(
    $config,
    new SynchronousStreamTransport(),
    new RequestFormatter(),
    new ResponseParser(
        maxHeaderCount: $config->getMaxHeaderCount(),
        maxHeaderLineLength: $config->getMaxHeaderLineLength(),
    ),
    verdict: new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason']),
    encapsulatedRequest: new HttpRequest('GET', '/upload', ['Host' => ['localhost']]),
));

$result = $client->scanFile('/avscan', __DIR__ . '/../eicar.com');

if (!$result->isInfected()) {
    echo 'Clean' . PHP_EOL;
} elseif ($result->getVirusName() !== null) {
    echo 'Virus: ' . $result->getVirusName() . PHP_EOL;
} else {
    // Untrusted server text — sanitize before displaying or recording outside this demo.
    echo 'Blocked: ' . ($result->getBlockReason() ?? 'policy') . PHP_EOL;
}

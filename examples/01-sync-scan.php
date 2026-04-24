<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;

$config = (new Config('127.0.0.1', 1344))->withVirusFoundHeaders([
    'X-Virus-Name',
    'X-Infection-Found',
    'X-Violations-Found',
    'X-Virus-ID',
]);

$client = new SynchronousIcapClient(new IcapClient(
    $config,
    new SynchronousStreamTransport(),
    new RequestFormatter(),
    new ResponseParser(
        maxHeaderCount: $config->getMaxHeaderCount(),
        maxHeaderLineLength: $config->getMaxHeaderLineLength(),
    ),
));

$eicarFile = __DIR__ . '/eicar.com';
if (!file_exists($eicarFile)) {
    // EICAR test signature, split so this file itself doesn't trip AV.
    file_put_contents(
        $eicarFile,
        'X5O!P%@AP[4\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
    );
}

echo 'Scanning ' . $eicarFile . ' synchronously...' . PHP_EOL;
$result = $client->scanFile('/avscan', $eicarFile);

echo $result->isInfected()
    ? 'Virus found: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
    : 'File is clean' . PHP_EOL;

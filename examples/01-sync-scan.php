<?php
require __DIR__ . '/../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;

$config = new Config('127.0.0.1', 1344);
$client = new SynchronousIcapClient(new IcapClient(
    $config,
    new SynchronousStreamTransport(),
    new RequestFormatter(),
    new ResponseParser()
));

$eicarFile = __DIR__ . '/eicar.com';
if (!file_exists($eicarFile)) {
    file_put_contents($eicarFile, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
}

echo "Scanning file $eicarFile synchronously...\n";
$result = $client->scanFile('/service', $eicarFile);

echo $result->isInfected()
    ? 'Virus found: ' . $result->getVirusName() . PHP_EOL
    : "File is clean\n";

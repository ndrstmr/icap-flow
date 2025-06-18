<?php
require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;

$icap = SynchronousIcapClient::create();

// Retrieve server options to determine preview size

$options = $icap->options('/service');
$previewSize = (int)($options->getOriginalResponse()->headers['Preview'][0] ?? 1024);

echo "Server preview size: $previewSize\n";

$result = $icap->scanFileWithPreview('/service', __DIR__ . '/../eicar.com', $previewSize);

echo $result->isInfected()
    ? 'Virus: ' . $result->getVirusName() . PHP_EOL
    : 'Clean' . PHP_EOL;

<?php
require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;

$icap = SynchronousIcapClient::create();

// Retrieve server options to determine preview size
$options = $icap->options('/service');
$previewSize = (int)($options->headers['Preview'][0] ?? 1024);

echo "Server preview size: $previewSize\n";

$response = $icap->scanFileWithPreview('/service', __DIR__ . '/../eicar.com', $previewSize);

echo 'ICAP Status: ' . $response->statusCode . PHP_EOL;

<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\SynchronousIcapClient;

/*
 * Discover the server's negotiated capabilities (RFC 3507 §4.10) and
 * use the advertised Preview size. Real deployments should cache this
 * answer for `Options-TTL` seconds; the next milestone after v2.0.0
 * adds a built-in PSR-16 cache decorator for that.
 */

$icap = SynchronousIcapClient::create();

$options = $icap->options('/avscan');
$headers = $options->getOriginalResponse()->headers;

$previewSize = (int) ($headers['Preview'][0] ?? 1024);

echo 'Methods:        ' . ($headers['Methods'][0] ?? '—') . PHP_EOL;
echo 'Preview size:   ' . $previewSize . PHP_EOL;
echo 'Max-Connections: ' . ($headers['Max-Connections'][0] ?? '—') . PHP_EOL;
echo 'Options-TTL:    ' . ($headers['Options-TTL'][0] ?? '—') . PHP_EOL;
echo 'ISTag:          ' . ($headers['ISTag'][0] ?? '—') . PHP_EOL;
echo PHP_EOL;

$result = $icap->scanFileWithPreview('/avscan', __DIR__ . '/../eicar.com', $previewSize);

echo $result->isInfected()
    ? 'Virus: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
    : 'Clean' . PHP_EOL;

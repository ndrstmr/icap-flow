<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\CicapVerdict;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;

/*
 * Discover the server's capabilities (RFC 3507 §4.10) and use the advertised
 * Preview size. With an OPTIONS cache, options() keeps the answer for its
 * Options-TTL (at most an hour), and scanFileWithPreview() without a size
 * takes its Preview, checked as a decimal and at most 1 MiB, else 1024. Do not
 * cast the header yourself: (int) reads "2abc" as 2.
 *
 * The client runs in the verified mode for the c-icap + ClamAV service of
 * docker-compose.yml (docs/cicap-clamav.md).
 */

$icap = new SynchronousIcapClient(new IcapClient(
    new Config('127.0.0.1'),
    new SynchronousStreamTransport(),
    new RequestFormatter(),
    new ResponseParser(),
    optionsCache: new InMemoryOptionsCache(),
    verdict: new CicapVerdict('/avscan'),
));

$options = $icap->options('/avscan');
$headers = $options->headers; // the names as the server spelled them

echo 'Methods:         ' . ($headers['Methods'][0] ?? '—') . PHP_EOL;
echo 'Preview:         ' . ($headers['Preview'][0] ?? '—') . PHP_EOL;
echo 'Max-Connections: ' . ($headers['Max-Connections'][0] ?? '—') . PHP_EOL;
echo 'Options-TTL:     ' . ($headers['Options-TTL'][0] ?? '—') . PHP_EOL;
echo 'ISTag:           ' . ($headers['ISTag'][0] ?? '—') . PHP_EOL;
echo PHP_EOL;

$result = $icap->scanFileWithPreview('/avscan', __DIR__ . '/../eicar.com');

echo $result->isInfected()
    ? 'Virus: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
    : 'Clean' . PHP_EOL;

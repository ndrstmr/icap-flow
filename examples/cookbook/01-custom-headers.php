<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\SynchronousIcapClient;

/*
 * Custom ICAP request headers — useful for downstream policy decisions
 * (X-Client-IP, X-Authenticated-User, X-Server-IP). Header names and
 * values are validated for CR/LF/NUL before any byte is written, and
 * library-managed headers (Encapsulated/Host/Connection/Preview/Allow)
 * always take precedence over caller input.
 */

$icap = SynchronousIcapClient::create();

$result = $icap->scanFile('/avscan', __DIR__ . '/../eicar.com', [
    'X-Client-IP'          => '203.0.113.5',
    'X-Authenticated-User' => base64_encode('user@example.org'),
    'X-Server-IP'          => '198.51.100.10',
]);

echo $result->isInfected()
    ? 'Virus: ' . $result->getVirusName() . PHP_EOL
    : 'Clean' . PHP_EOL;

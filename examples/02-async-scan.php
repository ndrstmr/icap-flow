<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapExceptionInterface;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Revolt\EventLoop;

EventLoop::run(function () {
    try {
        $config = (new Config('127.0.0.1', 1344))->withVirusFoundHeaders([
            'X-Virus-Name',
            'X-Infection-Found',
            'X-Violations-Found',
            'X-Virus-ID',
        ]);

        $eicarFile = __DIR__ . '/eicar.com';
        if (!file_exists($eicarFile)) {
            file_put_contents(
                $eicarFile,
                'X5O!P%@AP[4\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
            );
        }

        $client = new IcapClient(
            $config,
            new AsyncAmpTransport(),
            new RequestFormatter(),
            new ResponseParser(
                maxHeaderCount: $config->getMaxHeaderCount(),
                maxHeaderLineLength: $config->getMaxHeaderLineLength(),
            ),
        );

        echo 'Scanning ' . $eicarFile . ' asynchronously...' . PHP_EOL;
        $result = $client->scanFile('/avscan', $eicarFile)->await();

        echo $result->isInfected()
            ? 'Virus: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
            : 'File is clean' . PHP_EOL;
    } catch (IcapExceptionInterface $e) {
        echo 'ICAP error (' . $e::class . '): ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
});

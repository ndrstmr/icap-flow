<?php
require __DIR__ . '/../vendor/autoload.php';

use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Revolt\EventLoop;

EventLoop::run(function () {
    try {
        $config = new Config('127.0.0.1', 1344);
        $eicarFile = __DIR__ . '/eicar.com';
        if (!file_exists($eicarFile)) {
            file_put_contents($eicarFile, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
        }
        $client = new IcapClient(
            $config,
            new AsyncAmpTransport(),
            new RequestFormatter(),
            new ResponseParser()
        );

        echo "Scanning file $eicarFile asynchronously...\n";
        $response = $client->scanFile('/service', $eicarFile);
        

        echo "ICAP Status Code (async): " . $response->getStatusCode() . "\n";
        print_r($response->getHeaders());
    } catch (\Exception $e) {
        echo "An error occurred: " . $e->getMessage() . "\n";
    }
});

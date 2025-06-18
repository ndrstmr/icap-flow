<?php
require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\DTO\IcapRequest;

$icap = SynchronousIcapClient::create();

$request = (new IcapRequest('RESPMOD', 'icap://127.0.0.1/service'))
    ->withHeader('X-Client-IP', '203.0.113.5');

$result = $icap->request($request);

print_r($result->getOriginalResponse()->headers);

<?php

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;

it('implements TransportInterface', function () {
    $t = new SynchronousStreamTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on failure', function () {
    $t = new SynchronousStreamTransport();
    $config = new Config('256.256.256.256', 9999); // invalid host

    expect(fn () => $t->request($config, "")->await())->toThrow(IcapConnectionException::class);
});

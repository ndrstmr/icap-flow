<?php

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

it('implements TransportInterface', function () {
    $t = new AsyncAmpTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on invalid host', function () {
    $t = new AsyncAmpTransport();
    $config = new Config('127.0.0.1', 1); // unlikely to be open

        expect(fn () => $t->request($config, '')->await())->toThrow(IcapConnectionException::class);
    $this->runAsyncTest(function () use ($t, $config) {
        expect(fn () => $t->request($config, '')->await())->toThrow(IcapConnectionException::class);
    });
});

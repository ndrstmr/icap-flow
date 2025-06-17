<?php

use Mockery as m;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

it('orchestrates dependencies when calling options()', function () {
    $config = new Config('icap.example');

    $formatter = m::mock(RequestFormatterInterface::class);
    $transport = m::mock(TransportInterface::class);
    $parser = m::mock(ResponseParserInterface::class);

    $formatter->shouldReceive('format')
        ->once()
        ->withArgs(function ($req) {
            return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
        })
        ->andReturn('RAW');

    $transport->shouldReceive('request')->once()->with($config, 'RAW')
        ->andReturn(\Amp\Future::complete('RESP'));
    $responseObj = new IcapResponse(200);
    $parser->shouldReceive('parse')->once()->with('RESP')->andReturn($responseObj);

    $client = new IcapClient($config, $transport, $formatter, $parser);

    $this->runAsyncTest(function () use ($client, $responseObj) {
        $future = $client->options('/service');
        $res = $future->await();
        expect($res)->toBe($responseObj);
    });

    m::close();
});

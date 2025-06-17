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

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $formatter->shouldReceive('format')
        ->withArgs(function ($req) {
            return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
        })
        ->andReturn('RAW')
        ->once();

    $transport->shouldReceive('request')
        ->with($config, 'RAW')
        ->andReturn(\Amp\Future::complete('RESP'))
        ->once();

    $responseObj = new IcapResponse(200);

    $parser->shouldReceive('parse')
        ->with('RESP')
        ->andReturn($responseObj)
        ->once();

    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $responseObj) {
        $future = $client->options('/service');
        $res = $future->await();
        expect($res)->toBe($responseObj);
    });

    m::close();
});

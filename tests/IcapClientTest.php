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

    $formatExp = $formatter->shouldReceive('format')
    assert($formatExp instanceof \Mockery\ExpectationInterface);
    $formatExp->once();
    $transportExp = $transport->shouldReceive('request')->with($config, 'RAW')
    assert($transportExp instanceof \Mockery\ExpectationInterface);
    $transportExp->once();
    $parserExp = $parser->shouldReceive('parse')->with('RESP')->andReturn($responseObj);
    assert($parserExp instanceof \Mockery\ExpectationInterface);
    $parserExp->once();
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
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

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $responseObj) {
        $future = $client->options('/service');
        $res = $future->await();
        expect($res)->toBe($responseObj);
    });

    m::close();
});

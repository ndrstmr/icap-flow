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

    // Formatter expectations
    $formatterExp = $formatter->shouldReceive('format');
    assert($formatterExp instanceof \Mockery\ExpectationInterface);
    $formatterExp->withArgs(function ($req) {
        return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
    });
    $formatterExp->andReturn('RAW');
    $formatterExp->once();

    // Transport expectations
    $transportExp = $transport->shouldReceive('request');
    assert($transportExp instanceof \Mockery\ExpectationInterface);
    $transportExp->with($config, 'RAW');
    $transportExp->andReturn(\Amp\Future::complete('RESP'));
    $transportExp->once();

    // Parser expectations
    $responseObj = new IcapResponse(200);
    $parserExp = $parser->shouldReceive('parse');
    assert($parserExp instanceof \Mockery\ExpectationInterface);
    $parserExp->with('RESP');
    $parserExp->andReturn($responseObj);
    $parserExp->once();

    // Client and test execution
    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $responseObj) {
        $future = $client->options('/service');
        $res = $future->await();
        expect($res)->toBe($responseObj);
    });

    m::close();
});

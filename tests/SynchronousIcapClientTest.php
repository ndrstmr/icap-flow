<?php

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Transport\TransportInterface;

it('delegates calls to the async client and blocks for results', function () {
    $config = new Config('icap.example');

    $formatExp = $formatter->shouldReceive('format')->andReturn('RAW');
    assert($formatExp instanceof \Mockery\ExpectationInterface);
    $formatExp->once();

    $transportExp = $transport->shouldReceive('request')->with($config, 'RAW')
    assert($transportExp instanceof \Mockery\ExpectationInterface);
    $transportExp->once();

    $parserExp = $parser->shouldReceive('parse')->with('RESP')->andReturn($responseObj);
    assert($parserExp instanceof \Mockery\ExpectationInterface);
    $parserExp->once();
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    $formatter->shouldReceive('format')->once()->andReturn('RAW');
    $transport->shouldReceive('request')->once()->with($config, 'RAW')
        ->andReturn(\Amp\Future::complete('RESP'));
    $responseObj = new IcapResponse(200);
    $parser->shouldReceive('parse')->once()->with('RESP')->andReturn($responseObj);

    $async = new IcapClient($config, $transport, $formatter, $parser);
    $client = new SynchronousIcapClient($async);

    $res = $client->options('/service');

    expect($res)->toBe($responseObj);

    m::close();
});

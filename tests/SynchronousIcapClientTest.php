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

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    // Formatter expectations
    $formatterExp = $formatter->shouldReceive('format');
    assert($formatterExp instanceof \Mockery\ExpectationInterface);
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
    $async = new IcapClient($config, $transport, $formatter, $parser);
    $client = new SynchronousIcapClient($async);

    $res = $client->options('/service');

    expect($res)->toBe($responseObj);

    m::close();
});

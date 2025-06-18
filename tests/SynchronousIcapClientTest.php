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
    $formatter->shouldReceive('format')
        ->withArgs(function ($req) {
            return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
        })
        ->andReturn('RAW')
        ->once();

    // Transport expectations
    $transport->shouldReceive('request')
        ->with($config, 'RAW')
        ->andReturn(\Amp\Future::complete('RESP'))
        ->once();

    // Parser expectations
    $responseObj = new IcapResponse(200);
    $parser->shouldReceive('parse')
        ->with('RESP')
        ->andReturn($responseObj)
        ->once();

    // Client and test execution
    $async = new IcapClient($config, $transport, $formatter, $parser);
    $client = new SynchronousIcapClient($async);

    $res = $client->options('/service');

    expect($res)->toBe($responseObj);

    m::close();
});

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

    /** @var \Mockery\Expectation $formatterExp */
    $formatterExp = $formatter->shouldReceive('format');
    $formatterExp->withArgs(function ($req) {
        return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
    });
    $formatterExp->andReturn('RAW');
    $formatterExp->once();

    /** @var \Mockery\Expectation $transportExp */
    $transportExp = $transport->shouldReceive('request');
    $transportExp->with($config, 'RAW');
    $transportExp->andReturn(\Amp\Future::complete('RESP'));
    $transportExp->once();

    $responseObj = new IcapResponse(200);
    /** @var \Mockery\Expectation $parserExp */
    $parserExp = $parser->shouldReceive('parse');
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

it('invokes custom preview strategy when scanning with preview', function () {
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var \Ndrstmr\Icap\PreviewStrategyInterface&\Mockery\MockInterface $strategy */
    $strategy = m::mock(\Ndrstmr\Icap\PreviewStrategyInterface::class);

    /** @var \Mockery\Expectation $fmtExp */
    $fmtExp = $formatter->shouldReceive('format');
    $fmtExp->andReturn('RAW');

    /** @var \Mockery\Expectation $transExp */
    $transExp = $transport->shouldReceive('request');
    $transExp->andReturn(\Amp\Future::complete('RESP'));

    $previewResponse = new IcapResponse(204);
    /** @var \Mockery\Expectation $parserExp */
    $parserExp = $parser->shouldReceive('parse');
    $parserExp->andReturn($previewResponse);

    /** @var \Mockery\Expectation $strategyExp */
    $strategyExp = $strategy->shouldReceive('handlePreviewResponse');
    $strategyExp->with($previewResponse);
    $strategyExp->andReturn(\Ndrstmr\Icap\PreviewDecision::ABORT_CLEAN);
    $strategyExp->once();

    $client = new IcapClient($config, $transport, $formatter, $parser, $strategy);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'hi');

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $previewResponse) {
        $future = $client->scanFileWithPreview('/service', $tmp, 1);
        $res = $future->await();
        expect($res)->toBe($previewResponse);
    });

    unlink($tmp);
    m::close();
});

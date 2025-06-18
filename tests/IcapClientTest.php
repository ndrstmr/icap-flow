<?php

use Mockery as m;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
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
        expect($res)->toBeInstanceOf(ScanResult::class)
            ->and($res->getOriginalResponse())->toBe($responseObj)
            ->and($res->isInfected())->toBeFalse();
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
        expect($res)->toBeInstanceOf(ScanResult::class)
            ->and($res->getOriginalResponse())->toBe($previewResponse)
            ->and($res->isInfected())->toBeFalse();
    });

    unlink($tmp);
    m::close();
});

it('it handles exception from request formatter', function () {
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $fmtExp */
    $fmtExp = $formatter->shouldReceive('format');
    $fmtExp->andThrow(new RuntimeException('fail'));
    $transport->shouldNotReceive('request');

    $client = new IcapClient($config, $transport, $formatter, $parser);

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $future = $client->options('/svc');
        expect(fn () => $future->await())->toThrow(RuntimeException::class);
    });

    m::close();
});

it('correctly handles abort infected preview decision', function () {
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
    $transExp->once();
    $transExp->andReturn(\Amp\Future::complete('RESP'));
    $previewResponse = new IcapResponse(100);
    /** @var \Mockery\Expectation $parserExp */
    $parserExp = $parser->shouldReceive('parse');
    $parserExp->andReturn($previewResponse);
    /** @var \Mockery\Expectation $strExp */
    $strExp = $strategy->shouldReceive('handlePreviewResponse');
    $strExp->with($previewResponse);
    $strExp->once();
    $strExp->andReturn(\Ndrstmr\Icap\PreviewDecision::ABORT_INFECTED);

    $client = new IcapClient($config, $transport, $formatter, $parser, $strategy);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'hi');

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $previewResponse) {
        $future = $client->scanFileWithPreview('/service', $tmp, 1);
        $res = $future->await();
        expect($res)->toBeInstanceOf(ScanResult::class)
            ->and($res->getOriginalResponse())->toBe($previewResponse);
    });

    unlink($tmp);
    m::close();
});

it('correctly handles file size equal to preview size', function () {
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var \Ndrstmr\Icap\PreviewStrategyInterface&\Mockery\MockInterface $strategy */
    $strategy = m::mock(\Ndrstmr\Icap\PreviewStrategyInterface::class);

    $previewSize = 3;
    /** @var \Mockery\Expectation $fmt1 */
    $fmt1 = $formatter->shouldReceive('format');
    $fmt1->once();
    $fmt1->withArgs(function ($req) use ($previewSize) {
        return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest
            && $req->headers['Preview'][0] === (string)$previewSize
            && $req->body === 'abc';
    });
    $fmt1->andReturn('RAW1');
    /** @var \Mockery\Expectation $fmt2 */
    $fmt2 = $formatter->shouldReceive('format');
    $fmt2->once();
    $fmt2->withArgs(function ($req) {
        return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest
            && !isset($req->headers['Preview'])
            && $req->body === '';
    });
    $fmt2->andReturn('RAW2');

    /** @var \Mockery\Expectation $trans1 */
    $trans1 = $transport->shouldReceive('request');
    $trans1->with($config, 'RAW1');
    $trans1->once();
    $trans1->andReturn(\Amp\Future::complete('RESP1'));
    /** @var \Mockery\Expectation $trans2 */
    $trans2 = $transport->shouldReceive('request');
    $trans2->with($config, 'RAW2');
    $trans2->once();
    $trans2->andReturn(\Amp\Future::complete('RESP2'));

    $previewRes = new IcapResponse(100);
    $finalRes = new IcapResponse(200);
    /** @var \Mockery\Expectation $parser1 */
    $parser1 = $parser->shouldReceive('parse');
    $parser1->with('RESP1');
    $parser1->andReturn($previewRes);
    /** @var \Mockery\Expectation $parser2 */
    $parser2 = $parser->shouldReceive('parse');
    $parser2->with('RESP2');
    $parser2->andReturn($finalRes);
    /** @var \Mockery\Expectation $strExp */
    $strExp = $strategy->shouldReceive('handlePreviewResponse');
    $strExp->with($previewRes);
    $strExp->once();
    $strExp->andReturn(\Ndrstmr\Icap\PreviewDecision::CONTINUE_SENDING);

    $client = new IcapClient($config, $transport, $formatter, $parser, $strategy);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    /** @var \Ndrstmr\Icap\Tests\AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $previewSize, $finalRes) {
        $future = $client->scanFileWithPreview('/service', $tmp, $previewSize);
        $res = $future->await();
        expect($res)->toBeInstanceOf(ScanResult::class)
            ->and($res->getOriginalResponse())->toBe($finalRes);
    });

    unlink($tmp);
    m::close();
});

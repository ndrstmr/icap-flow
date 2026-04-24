<?php

declare(strict_types=1);

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

/**
 * @return array{Config, RequestFormatterInterface&\Mockery\MockInterface, TransportInterface&\Mockery\MockInterface, ResponseParserInterface&\Mockery\MockInterface, PreviewStrategyInterface&\Mockery\MockInterface, IcapClient}
 */
function makeClient(): array
{
    $config = new Config('icap.example');

    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);
    /** @var PreviewStrategyInterface&\Mockery\MockInterface $strategy */
    $strategy = m::mock(PreviewStrategyInterface::class);

    $client = new IcapClient($config, $transport, $formatter, $parser, $strategy);

    return [$config, $formatter, $transport, $parser, $strategy, $client];
}

it('OPTIONS: orchestrates formatter → transport → parser', function () {
    [$config, $formatter, $transport, $parser, , $client] = makeClient();

    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(fn (IcapRequest $req) => $req->method === 'OPTIONS' && str_contains($req->uri, '/service'));
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->withArgs(fn ($cfg, $chunks) => $cfg === $config && is_iterable($chunks));
    $trans->andReturn(\Amp\Future::complete('RESP'));

    $resp = new IcapResponse(200);
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->once();
    $p->with('RESP');
    $p->andReturn($resp);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $resp) {
        $res = $client->options('/service')->await();
        expect($res)->toBeInstanceOf(ScanResult::class)
            ->and($res->getOriginalResponse())->toBe($resp);
    });

    m::close();
});

it('scanFile wraps the file as an encapsulated HttpResponse with Content-Length', function () {
    [, $formatter, $transport, $parser, , $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload-bytes');

    $captured = null;
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return $req->method === 'RESPMOD' && $req->encapsulatedResponse !== null;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $t2 */
    $t2 = $transport->shouldReceive('request');
    $t2->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $p2 */
    $p2 = $parser->shouldReceive('parse');
    $p2->andReturn(new IcapResponse(204));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
        $client->scanFile('/service', $tmp)->await();
        assert($captured instanceof IcapRequest);
        $encapsulated = $captured->encapsulatedResponse;
        assert($encapsulated !== null);
        expect($encapsulated->statusCode)->toBe(200)
            ->and($encapsulated->headers['Content-Length'])->toBe(['13'])
            ->and(is_resource($encapsulated->body))->toBeTrue();
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview marks the preview complete (ieof) when file size <= preview size — no continuation request', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    $captured = null;
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return true;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->andReturn(\Amp\Future::complete('RESP'));
    /** @var \Mockery\Expectation $pp */
    $pp = $parser->shouldReceive('parse');
    $pp->andReturn(new IcapResponse(204));
    $strategy->shouldNotReceive('handlePreviewResponse');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        assert($captured instanceof IcapRequest);
        expect($captured->previewIsComplete)->toBeTrue()
            ->and($captured->headers['Preview'])->toBe(['3'])
            ->and($captured->headers['Allow'])->toBe(['204'])
            ->and($res->isInfected())->toBeFalse();
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview consults strategy and sends remainder on CONTINUE_SENDING', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var IcapRequest[] $calls */
    $calls = [];
    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->twice();
    $fmt->withArgs(function (IcapRequest $req) use (&$calls) {
        $calls[] = $req;
        return true;
    });
    $fmt->andReturn(['HEAD']);

    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->twice();
    $trans->andReturn(
        \Amp\Future::complete('RESP1'),
        \Amp\Future::complete('RESP2'),
    );
    $previewResp = new IcapResponse(100);
    $finalResp = new IcapResponse(200);
    /** @var \Mockery\Expectation $pr1 */
    $pr1 = $parser->shouldReceive('parse');
    $pr1->once();
    $pr1->with('RESP1');
    $pr1->andReturn($previewResp);
    /** @var \Mockery\Expectation $pr2 */
    $pr2 = $parser->shouldReceive('parse');
    $pr2->once();
    $pr2->with('RESP2');
    $pr2->andReturn($finalResp);

    /** @var \Mockery\Expectation $s */
    $s = $strategy->shouldReceive('handlePreviewResponse');
    $s->once();
    $s->with($previewResp);
    $s->andReturn(PreviewDecision::CONTINUE_SENDING);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $finalResp, &$calls) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        expect($res->getOriginalResponse())->toBe($finalResp)
            ->and($calls[0]->previewIsComplete)->toBeFalse()
            ->and($calls[0]->headers['Preview'])->toBe(['3'])
            ->and($calls[0]->headers['Allow'])->toBe(['204'])
            ->and(isset($calls[1]->headers['Preview']))->toBeFalse();
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview stops on ABORT_INFECTED without sending the remainder', function () {
    [, $formatter, $transport, $parser, $strategy, $client] = makeClient();

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abcdef');

    /** @var \Mockery\Expectation $fmt */
    $fmt = $formatter->shouldReceive('format');
    $fmt->once();
    $fmt->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $trans */
    $trans = $transport->shouldReceive('request');
    $trans->once();
    $trans->andReturn(\Amp\Future::complete('RESP1'));
    $previewResp = new IcapResponse(100);
    /** @var \Mockery\Expectation $pp */
    $pp = $parser->shouldReceive('parse');
    $pp->andReturn($previewResp);
    /** @var \Mockery\Expectation $s */
    $s = $strategy->shouldReceive('handlePreviewResponse');
    $s->once();
    $s->andReturn(PreviewDecision::ABORT_INFECTED);

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $previewResp) {
        $res = $client->scanFileWithPreview('/service', $tmp, 3)->await();
        expect($res->getOriginalResponse())->toBe($previewResp);
    });

    unlink($tmp);
    m::close();
});

it('propagates exceptions from the formatter', function () {
    [, $formatter, $transport, , , $client] = makeClient();

    /** @var \Mockery\Expectation $ff */
    $ff = $formatter->shouldReceive('format');
    $ff->andThrow(new RuntimeException('fail'));
    $transport->shouldNotReceive('request');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(RuntimeException::class);
    });

    m::close();
});

<?php

declare(strict_types=1);

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

/**
 * M3.6 — custom ICAP request headers.
 *
 * Real deployments need to pass `X-Client-IP`, `X-Authenticated-User`,
 * `X-Server-IP` etc. through to the ICAP server for policy decisions
 * (RFC 3507 §4.3.2 + de-facto vendor additions). Both scanFile and
 * scanFileWithPreview must accept an optional $extraHeaders map.
 */

it('scanFile merges caller-supplied headers into the outgoing request', function () {
    $captured = null;

    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return true;
    });
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'payload');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
        $client->scanFile('/svc', $tmp, [
            'X-Client-IP'          => '203.0.113.5',
            'X-Authenticated-User' => 'dGVzdA==',
        ])->await();

        assert($captured instanceof IcapRequest);
        expect($captured->headers['X-Client-IP'])->toBe(['203.0.113.5'])
            ->and($captured->headers['X-Authenticated-User'])->toBe(['dGVzdA==']);
    });

    unlink($tmp);
    m::close();
});

it('scanFileWithPreview merges caller-supplied headers without overriding Preview/Allow', function () {
    $captured = null;

    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->withArgs(function (IcapRequest $req) use (&$captured) {
        $captured = $req;
        return true;
    });
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn(new IcapResponse(204));

    $client = new IcapClient($config, $transport, $formatter, $parser);

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, 'abc');

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, &$captured) {
        $client->scanFileWithPreview('/svc', $tmp, 3, [
            'X-Client-IP' => '203.0.113.99',
            // Caller tries to override Preview — library-managed
            // headers MUST win to keep the protocol state sound.
            'Preview'     => 'ignored',
            'Allow'       => 'also-ignored',
        ])->await();

        assert($captured instanceof IcapRequest);
        expect($captured->headers['X-Client-IP'])->toBe(['203.0.113.99'])
            ->and($captured->headers['Preview'])->toBe(['3'])
            ->and($captured->headers['Allow'])->toBe(['204']);
    });

    unlink($tmp);
    m::close();
});

it('rejects header names that contain CR/LF (injection)', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->scanFile('/svc', __FILE__, ["X-Bad\r\nInjected" => 'value']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects header values that contain CR/LF (injection)', function () {
    $client = IcapClient::forServer('icap.example');
    expect(fn () => $client->scanFile('/svc', __FILE__, ['X-Bad' => "value\r\nInjected: 1"]))
        ->toThrow(InvalidArgumentException::class);
});

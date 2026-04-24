<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * You may obtain a copy of the Licence at:
 *
 *     https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 */

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\IcapClientInterface;
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

    /** @var \Mockery\Expectation $formatterExp */
    $formatterExp = $formatter->shouldReceive('format');
    $formatterExp->withArgs(function ($req) {
        return $req instanceof \Ndrstmr\Icap\DTO\IcapRequest && $req->method === 'OPTIONS' && str_contains($req->uri, '/service');
    });
    $formatterExp->andReturn(['HEAD']);
    $formatterExp->once();

    /** @var \Mockery\Expectation $transportExp */
    $transportExp = $transport->shouldReceive('request');
    $transportExp->withArgs(fn ($cfg, $chunks) => $cfg === $config && is_iterable($chunks));
    $transportExp->andReturn(\Amp\Future::complete('RESP'));
    $transportExp->once();

    $responseObj = new IcapResponse(200);
    /** @var \Mockery\Expectation $parserExp */
    $parserExp = $parser->shouldReceive('parse');
    $parserExp->with('RESP');
    $parserExp->andReturn($responseObj);
    $parserExp->once();

    // Client and test execution
    $async = new IcapClient($config, $transport, $formatter, $parser);
    $client = new SynchronousIcapClient($async);

    $res = $client->options('/service');

    expect($res)->toBeInstanceOf(ScanResult::class)
        ->and($res->getOriginalResponse())->toBe($responseObj);

    m::close();
});

it('scanFile delegates correctly to async client', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $async */
    $async = m::mock(IcapClientInterface::class);
    $response = new IcapResponse(201);
    $result = new ScanResult(false, null, $response);
    /** @var \Mockery\Expectation $exp */
    $exp = $async->shouldReceive('scanFile');
    $exp->with('/service', '/tmp/file', []);
    $exp->once();
    $exp->andReturn(\Amp\Future::complete($result));

    $client = new SynchronousIcapClient($async);

    $res = $client->scanFile('/service', '/tmp/file');

    expect($res)->toBe($result);

    m::close();
});

it('request delegates correctly to async client', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $async */
    $async = m::mock(IcapClientInterface::class);
    $req = new \Ndrstmr\Icap\DTO\IcapRequest('OPTIONS', 'icap://icap.example');
    $response = new IcapResponse(200);
    $result = new ScanResult(false, null, $response);
    /** @var \Mockery\Expectation $exp */
    $exp = $async->shouldReceive('request');
    $exp->with($req);
    $exp->once();
    $exp->andReturn(\Amp\Future::complete($result));

    $client = new SynchronousIcapClient($async);

    $res = $client->request($req);

    expect($res)->toBe($result);

    m::close();
});

it('it handles and rethrows exceptions from async client', function () {
    /** @var IcapClientInterface&\Mockery\MockInterface $async */
    $async = m::mock(IcapClientInterface::class);
    $exception = new \Ndrstmr\Icap\Exception\IcapConnectionException('fail');
    /** @var \Mockery\Expectation $exp */
    $exp = $async->shouldReceive('scanFile');
    $exp->with('/service', '/tmp/file', []);
    $exp->once();
    $exp->andReturn(\Amp\Future::error($exception));

    $client = new SynchronousIcapClient($async);

    expect(fn () => $client->scanFile('/service', '/tmp/file'))
        ->toThrow(\Ndrstmr\Icap\Exception\IcapConnectionException::class);

    m::close();
});

it('static create factory returns a usable client', function () {
    $client = SynchronousIcapClient::create();
    expect($client)->toBeInstanceOf(SynchronousIcapClient::class);
});

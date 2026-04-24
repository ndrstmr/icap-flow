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

declare(strict_types=1);

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;
use Psr\Log\LoggerInterface;

uses(AsyncTestCase::class);

/**
 * M3.1 — PSR-3 logger observability.
 *
 * The client must emit structured events at request start and at
 * request completion (with status code). Deployments in the public
 * sector rely on this for BSI-Grundschutz-compatible auditing.
 */

it('logs a request-started event with the ICAP method + service URI', function () {
    /** @var LoggerInterface&\Mockery\MockInterface $logger */
    $logger = m::mock(LoggerInterface::class);

    /** @var \Mockery\Expectation $start */
    $start = $logger->shouldReceive('info');
    $start->once();
    $start->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'ICAP request')
            && ($ctx['method'] ?? null) === 'OPTIONS'
            && str_contains((string) ($ctx['uri'] ?? ''), '/svc');
    });

    /** @var \Mockery\Expectation $done */
    $done = $logger->shouldReceive('info');
    $done->once();
    $done->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'completed')
            && ($ctx['statusCode'] ?? null) === 204;
    });

    [$client] = buildClientWithLogger($logger, new IcapResponse(204));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        $client->options('/svc')->await();
    });

    m::close();
});

it('logs a warning when the request raises an exception', function () {
    /** @var LoggerInterface&\Mockery\MockInterface $logger */
    $logger = m::mock(LoggerInterface::class);

    // Start event always fires.
    /** @var \Mockery\Expectation $start */
    $start = $logger->shouldReceive('info');
    $start->once();
    // Warning carries the status code when available.
    /** @var \Mockery\Expectation $warn */
    $warn = $logger->shouldReceive('warning');
    $warn->once();
    $warn->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'failed')
            && ($ctx['statusCode'] ?? null) === 503;
    });

    [$client] = buildClientWithLogger($logger, new IcapResponse(503));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client) {
        expect(fn () => $client->options('/svc')->await())->toThrow(\Ndrstmr\Icap\Exception\IcapServerException::class);
    });

    m::close();
});

/**
 * @return array{IcapClient}
 */
function buildClientWithLogger(LoggerInterface $logger, IcapResponse $canned): array
{
    $config = new Config('icap.example');
    /** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
    $formatter = m::mock(RequestFormatterInterface::class);
    /** @var TransportInterface&\Mockery\MockInterface $transport */
    $transport = m::mock(TransportInterface::class);
    /** @var ResponseParserInterface&\Mockery\MockInterface $parser */
    $parser = m::mock(ResponseParserInterface::class);

    /** @var \Mockery\Expectation $f */
    $f = $formatter->shouldReceive('format');
    $f->andReturn(['HEAD']);
    /** @var \Mockery\Expectation $t */
    $t = $transport->shouldReceive('request');
    $t->andReturn(\Amp\Future::complete('RAW'));
    /** @var \Mockery\Expectation $p */
    $p = $parser->shouldReceive('parse');
    $p->andReturn($canned);

    return [new IcapClient($config, $transport, $formatter, $parser, null, $logger)];
}

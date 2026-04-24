<?php

declare(strict_types=1);

use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapExceptionInterface;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapResponseException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;

it('catches every concrete ICAP exception via the marker interface', function () {
    $exceptions = [
        new IcapConnectionException('conn'),
        new IcapResponseException('resp'),
        new IcapProtocolException('proto'),
        new IcapTimeoutException('to'),
        new IcapClientException('4xx', 400),
        new IcapServerException('5xx', 503),
        new IcapMalformedResponseException('bad bytes'),
    ];

    foreach ($exceptions as $e) {
        $caught = false;
        try {
            throw $e;
        } catch (IcapExceptionInterface) {
            $caught = true;
        }
        expect($caught)->toBeTrue($e::class . ' should be catchable as IcapExceptionInterface');
        expect($e)->toBeInstanceOf(\RuntimeException::class);
    }
});

it('places malformed responses under the protocol exception', function () {
    expect(fn () => throw new IcapMalformedResponseException('invalid status line'))
        ->toThrow(IcapProtocolException::class, 'invalid status line');
});

it('distinguishes 4xx client errors from 5xx server errors', function () {
    $client = new IcapClientException('bad request', 400);
    $server = new IcapServerException('service unavailable', 503);

    expect($client->getCode())->toBe(400)
        ->and($server->getCode())->toBe(503)
        ->and($client)->toBeInstanceOf(IcapExceptionInterface::class)
        ->and($server)->toBeInstanceOf(IcapExceptionInterface::class);

    // 4xx must NOT be catchable as 5xx.
    expect(fn () => throw $client)->toThrow(IcapClientException::class);
    expect($client)->not->toBeInstanceOf(IcapServerException::class);
    expect($server)->not->toBeInstanceOf(IcapClientException::class);
});

it('treats IcapTimeoutException as a leaf type, not a protocol exception', function () {
    $timeout = new IcapTimeoutException('connect timed out');
    expect($timeout)->toBeInstanceOf(IcapExceptionInterface::class)
        ->and($timeout)->not->toBeInstanceOf(IcapProtocolException::class);
});

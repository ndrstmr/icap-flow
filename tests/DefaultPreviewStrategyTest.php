<?php

use Ndrstmr\Icap\DefaultPreviewStrategy;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapResponseException;

it('returns abort clean on 204', function () {
    $strategy = new DefaultPreviewStrategy();
    $res = $strategy->handlePreviewResponse(new IcapResponse(204));
    expect($res)->toBe(PreviewDecision::ABORT_CLEAN);
});

it('returns continue on 100', function () {
    $strategy = new DefaultPreviewStrategy();
    $res = $strategy->handlePreviewResponse(new IcapResponse(100));
    expect($res)->toBe(PreviewDecision::CONTINUE_SENDING);
});

it('throws on unexpected codes', function () {
    $strategy = new DefaultPreviewStrategy();
    expect(fn () => $strategy->handlePreviewResponse(new IcapResponse(500)))->toThrow(IcapResponseException::class);
});

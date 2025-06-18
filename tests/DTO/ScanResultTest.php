<?php

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;

it('stores infection state and original response', function () {
    $resp = new IcapResponse(204);
    $result = new ScanResult(false, null, $resp);

    expect($result->isInfected())->toBeFalse()
        ->and($result->getVirusName())->toBeNull()
        ->and($result->getOriginalResponse())->toBe($resp);
});

it('can store virus name', function () {
    $resp = new IcapResponse(200, ['X-Virus-Name' => ['EICAR']]);
    $result = new ScanResult(true, 'EICAR', $resp);

    expect($result->isInfected())->toBeTrue()
        ->and($result->getVirusName())->toBe('EICAR')
        ->and($result->getOriginalResponse())->toBe($resp);
});

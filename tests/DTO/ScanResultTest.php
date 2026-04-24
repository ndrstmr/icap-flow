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

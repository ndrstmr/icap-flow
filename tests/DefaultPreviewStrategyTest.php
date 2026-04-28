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

// v2.1.1-B: RFC 3507 §4.3.3 / §6 — server may respond 200/206 in preview
// if it detects malware in the first chunk without reading the full body.

it('returns abort infected when 200 preview response carries a virus header', function () {
    $strategy = new DefaultPreviewStrategy(['X-Virus-Name']);
    $response = new IcapResponse(200, ['X-Virus-Name' => ['Eicar-Test-Signature']]);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_INFECTED);
});

it('returns abort infected when 206 preview response carries a virus header', function () {
    $strategy = new DefaultPreviewStrategy(['X-Virus-Name']);
    $response = new IcapResponse(206, ['X-Virus-Name' => ['Win.Malware.Generic']]);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_INFECTED);
});

it('returns abort clean when 200 preview response carries no virus header', function () {
    $strategy = new DefaultPreviewStrategy(['X-Virus-Name']);
    $response = new IcapResponse(200, ['X-Response-Desc' => ['OK']]);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_CLEAN);
});

it('returns abort clean when 206 preview response carries no virus header', function () {
    $strategy = new DefaultPreviewStrategy(['X-Virus-Name']);
    $response = new IcapResponse(206);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_CLEAN);
});

it('checks all configured vendor virus headers for infected verdict', function () {
    $strategy = new DefaultPreviewStrategy(['X-Virus-ID', 'X-Violations-Found', 'X-Virus-Name']);
    $response = new IcapResponse(200, ['X-Violations-Found' => ['1']]);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_INFECTED);
});

it('uses default virus header list when constructed without arguments', function () {
    $strategy = new DefaultPreviewStrategy();
    $response = new IcapResponse(200, ['X-Virus-Name' => ['Eicar-Test-Signature']]);
    expect($strategy->handlePreviewResponse($response))->toBe(PreviewDecision::ABORT_INFECTED);
});

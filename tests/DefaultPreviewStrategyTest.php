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

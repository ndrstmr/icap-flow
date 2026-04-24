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

it('can be instantiated and mutated immutably', function () {
    $res = new IcapResponse(200);
    expect($res->statusCode)->toBe(200);
    $res2 = $res->withHeader('X-Test', '1');
    expect($res2)->not->toBe($res)
        ->and($res2->headers['X-Test'])->toEqual(['1']);
});

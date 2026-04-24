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

use Ndrstmr\Icap\Config;

test('Config can be instantiated', function () {
    $config = new Config('icap.example');
    expect($config)->toBeInstanceOf(Config::class)
        ->and($config->host)->toBe('icap.example')
        ->and($config->port)->toBe(1344)
        ->and($config->getSocketTimeout())->toBe(10.0)
        ->and($config->getStreamTimeout())->toBe(10.0);
});

test('virus header can be customized', function () {
    $config = new Config('icap.example');
    $new = $config->withVirusFoundHeader('X-Infection-Found');

    expect($new)->not->toBe($config)
        ->and($new->getVirusFoundHeader())->toBe('X-Infection-Found')
        ->and($config->getVirusFoundHeader())->toBe('X-Virus-Name');
});

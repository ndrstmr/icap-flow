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
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

it('implements TransportInterface', function () {
    $t = new AsyncAmpTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on invalid host', function () {
    $t = new AsyncAmpTransport();
    $config = new Config('127.0.0.1', 1); // unlikely to be open

    expect(fn () => $t->request($config, [])->await())->toThrow(IcapConnectionException::class);
});

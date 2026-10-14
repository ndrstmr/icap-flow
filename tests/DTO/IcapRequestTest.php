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

use Ndrstmr\Icap\DTO\IcapRequest;

it('can be instantiated and mutated immutably', function () {
    $req = new IcapRequest('REQMOD', 'icap://icap.example/');
    expect($req->method)->toBe('REQMOD')
        ->and($req->uri)->toBe('icap://icap.example/');
    $req2 = $req->withHeader('X-Test', '1');
    expect($req2)->not->toBe($req)
        ->and($req2->headers['X-Test'])->toEqual(['1']);
});

it('keeps string and list header values and refuses any other type instead of casting it', function () {
    $uri = 'icap://icap.example/scan';

    $req = new IcapRequest('OPTIONS', $uri, ['X-One' => 'a', 'X-Two' => ['b', 'c']]);
    expect($req->headers)->toBe(['X-One' => ['a'], 'X-Two' => ['b', 'c']]);

    // A cast would turn null into no field at all and an object into its
    // properties. Callers without static analysis can pass any PHP type,
    // so these header maps go in untyped.
    $withHeaders = fn (array $headers): IcapRequest => new IcapRequest('OPTIONS', $uri, $headers);
    $cases = [
        'null'   => ['X-Null' => null],
        'object' => ['X-Object' => (object) ['value' => 'safe']],
        'int'    => ['X-Count' => 5],
    ];

    foreach ($cases as $case => $headers) {
        expect(fn () => $withHeaders($headers))->toThrow(InvalidArgumentException::class, '', $case);
    }
});

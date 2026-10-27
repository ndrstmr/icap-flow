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

it('is the start of a message only if it announces a preview and does not end in ieof', function () {
    $uri = 'icap://icap.example/scan';

    // [ICAP fields, the body ends in ieof, whether the request is the start of a message]
    $cases = [
        'no preview' => [[], false, false],
        'no preview, ieof' => [[], true, false],
        'a preview' => [['Preview' => '1'], false, true],
        'a preview as a list' => [['Preview' => ['1']], false, true],
        'a preview of 0' => [['Preview' => '0'], false, true],
        'the field in lower case' => [['preview' => '1'], false, true],
        'the field in upper case' => [['PREVIEW' => '1'], false, true],
        'a preview that ends in ieof' => [['Preview' => '1'], true, false],
        'a field without a value' => [['Preview' => []], false, false],
        'another field' => [['X-Preview' => '1', 'Previews' => '1'], false, false],
    ];
    foreach ($cases as $label => [$headers, $ieof, $partial]) {
        expect((new IcapRequest('RESPMOD', $uri, $headers, previewIsComplete: $ieof))->isPartialPreview())->toBe($partial, $label);
    }
});

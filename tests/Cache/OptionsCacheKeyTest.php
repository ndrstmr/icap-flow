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

declare(strict_types=1);

namespace Ndrstmr\Icap\Tests\Cache;

use Ndrstmr\Icap\Cache\OptionsCacheKey;

/*
 * M12: the key of an OPTIONS entry is a hash of structured parts. Parts that
 * run into each other when written one after the other must still give
 * different keys, and a key carries nothing a backend could refuse.
 */
it('derives a key of 64 hex digits from endpoint and service, the same one every time', function () {
    $key = OptionsCacheKey::forService('icap.example', 1344, '/avscan');

    expect(preg_match('/^[0-9a-f]{64}\z/', $key))->toBe(1);
    expect(OptionsCacheKey::forService('icap.example', 1344, '/avscan'))->toBe($key);
});

it('tells endpoint and service apart, however their parts run together', function () {
    $keys = [
        'base'                   => OptionsCacheKey::forService('icap.example', 1344, '/avscan'),
        'other host'             => OptionsCacheKey::forService('icap2.example', 1344, '/avscan'),
        'other port'             => OptionsCacheKey::forService('icap.example', 1345, '/avscan'),
        'other service'          => OptionsCacheKey::forService('icap.example', 1344, '/other'),
        'other query'            => OptionsCacheKey::forService('icap.example', 1344, '/avscan?a=b'),
        'port into the service'  => OptionsCacheKey::forService('icap.example', 134, '4/avscan'),
        // host:port + service reads 'h:1:2/s' for both
        'host:port run together' => OptionsCacheKey::forService('h', 1, ':2/s'),
        'host with a colon'      => OptionsCacheKey::forService('h:1', 2, '/s'),
    ];

    expect(count(array_unique($keys)))->toBe(count($keys));
});

it('derives the backend key from prefix, role and key without ambiguity', function () {
    $keys = [
        'base'                 => OptionsCacheKey::backend('icap.', OptionsCacheKey::DATA, 'k'),
        'other prefix'         => OptionsCacheKey::backend('icap2.', OptionsCacheKey::DATA, 'k'),
        'other key'            => OptionsCacheKey::backend('icap.', OptionsCacheKey::DATA, 'k2'),
        'ISTag role'           => OptionsCacheKey::backend('icap.', OptionsCacheKey::ISTAG),
        'registry role'        => OptionsCacheKey::backend('icap.', OptionsCacheKey::REGISTRY),
        'ISTag role with key'  => OptionsCacheKey::backend('icap.', OptionsCacheKey::ISTAG, 'k'),
        'prefix into the key'  => OptionsCacheKey::backend('icap', OptionsCacheKey::DATA, '.k'),
        'key into the prefix'  => OptionsCacheKey::backend('icap.k', OptionsCacheKey::DATA, ''),
        'no prefix'            => OptionsCacheKey::backend('', OptionsCacheKey::DATA, 'icap.k'),
        'client key as a key'  => OptionsCacheKey::forService('icap.example', 1344, '/avscan'),
    ];

    expect(count(array_unique($keys)))->toBe(count($keys));
    foreach ($keys as $case => $key) {
        expect(preg_match('/^[0-9a-f]{64}\z/', $key))->toBe(1, $case);
    }
    expect(OptionsCacheKey::backend('icap.', OptionsCacheKey::DATA, 'k'))->toBe($keys['base']);
});

it('keeps the scheme of version 1, so that a persistent cache finds its entries after an upgrade', function () {
    // Each part as "<length>:<part>;", the scheme and its version first.
    expect(OptionsCacheKey::forService('icap.example', 1344, '/avscan'))
        ->toBe(hash('sha256', '20:icap-flow/options/v1;12:icap.example;4:1344;7:/avscan;'))
        ->toBe('013d7321e70509344d90acaa7280a697f4b959ed2332b08fee3e85d7edb006cc');
    expect(OptionsCacheKey::backend('icap.', OptionsCacheKey::DATA, 'k'))
        ->toBe(hash('sha256', '26:icap-flow/options-cache/v1;5:icap.;4:data;1:k;'))
        ->toBe('28bc2f6bf0c6776f9c57f8e9a9e65de7a8f3d62fbf109db899fcdce795c32259');
});

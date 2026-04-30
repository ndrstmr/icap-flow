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

use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * v2.2-T + v2.2-U — ISTag-based invalidation and injectable clock.
 *
 * RFC 3507 §4.10.2: the ISTag (Implementation Status Tag) changes
 * whenever the server's configuration, signature database, or code
 * is updated. Cached OPTIONS responses must be invalidated when the
 * ISTag changes, even if the TTL has not yet expired.
 *
 * v2.2-U: InMemoryOptionsCache now accepts an optional clock closure
 * instead of using time() directly, enabling deterministic TTL tests
 * without the old advanceClockForTesting() test seam.
 */

it('injectable clock replaces advanceClockForTesting for TTL expiry', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    $cache->set('k', new IcapResponse(200), 10);
    expect($cache->get('k'))->not->toBeNull();

    $now = 1011;
    expect($cache->get('k'))->toBeNull();
});

it('uses time() by default when no clock is injected', function () {
    $cache = new InMemoryOptionsCache();
    $cache->set('k', new IcapResponse(200), 3600);
    // Entry should be present immediately (TTL not expired).
    expect($cache->get('k'))->not->toBeNull();
});

it('invalidates cached entry when ISTag changes', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    $cache->set('k', new IcapResponse(200), 3600, 'tag-v1');
    expect($cache->get('k'))->not->toBeNull();

    // Same ISTag — entry remains.
    $cache->set('k', new IcapResponse(200, ['Methods' => ['RESPMOD']]), 3600, 'tag-v1');
    expect($cache->get('k')?->headers['Methods'] ?? null)->toBe(['RESPMOD']);

    // Different ISTag — old entry is replaced.
    $cache->set('k', new IcapResponse(200, ['Methods' => ['REQMOD']]), 3600, 'tag-v2');
    expect($cache->get('k')?->headers['Methods'] ?? null)->toBe(['REQMOD']);
});

it('treats null ISTag as compatible with any stored ISTag', function () {
    $cache = new InMemoryOptionsCache();

    // Store with ISTag.
    $cache->set('k', new IcapResponse(200), 3600, 'tag-v1');
    expect($cache->get('k'))->not->toBeNull();

    // Overwrite without ISTag — should replace, not reject.
    $cache->set('k', new IcapResponse(204), 3600);
    expect($cache->get('k')?->statusCode)->toBe(204);
});

it('evicts a different-key entry when ISTag is global and changes', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    // Two different services cached with the same ISTag.
    $cache->set('svc-a', new IcapResponse(200), 3600, 'tag-v1');
    $cache->set('svc-b', new IcapResponse(200), 3600, 'tag-v1');

    // ISTag changes — store svc-a with new tag.
    $cache->set('svc-a', new IcapResponse(200), 3600, 'tag-v2');

    // svc-b's cached entry should be evicted because the global ISTag
    // has advanced — its cached ISTag is now stale.
    expect($cache->get('svc-b'))->toBeNull();
    expect($cache->get('svc-a'))->not->toBeNull();
});

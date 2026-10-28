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
use Ndrstmr\Icap\Tests\Cache\StoredEntryCases;

/**
 * The process-local OPTIONS cache: an entry lives for its TTL, at most an hour, and the cache holds a bounded number
 * of them. A tag belongs to its service and invalidates nothing else.
 */

/**
 * @return int the number of entries the cache holds, expired ones included
 */
function memoryCacheHeld(InMemoryOptionsCache $cache): int
{
    $entries = (new ReflectionProperty($cache, 'entries'))->getValue($cache);

    return is_array($entries) ? count($entries) : -1;
}

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

it('ends an entry at its own end, and reading does not extend it', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    $cache->set('k', new IcapResponse(200), 10);
    $now = 1009;
    expect($cache->get('k'))->not->toBeNull();
    expect($cache->get('k'))->not->toBeNull();
    $now = 1010;
    expect($cache->get('k'))->toBeNull();
});

it('replaces the entry of a key as a whole, with or without a tag', function () {
    $cache = new InMemoryOptionsCache();

    $cache->set('k', new IcapResponse(200, ['Methods' => ['RESPMOD']]), 3600, 'tag-v1');
    $cache->set('k', new IcapResponse(200, ['Methods' => ['REQMOD']]), 3600, 'tag-v2');
    expect($cache->get('k')?->headers['Methods'])->toBe(['REQMOD']);

    $cache->set('k', new IcapResponse(200, ['Methods' => ['RESPMOD']]), 3600);
    expect($cache->get('k')?->headers['Methods'])->toBe(['RESPMOD']);
});

it('keeps the entries of the other services when a service reports a new ISTag', function () {
    $cache = new InMemoryOptionsCache();

    $cache->set('svc-a', new IcapResponse(200, ['Service' => ['a']]), 3600, 'tag-v1');
    $cache->set('svc-b', new IcapResponse(200, ['Service' => ['b']]), 3600, 'tag-v1');
    // The tag belongs to svc-a. It invalidates nothing of svc-b.
    $cache->set('svc-a', new IcapResponse(200, ['Service' => ['a, newer']]), 3600, 'tag-v2');

    expect($cache->get('svc-a')?->headers['Service'])->toBe(['a, newer']);
    expect($cache->get('svc-b')?->headers['Service'])->toBe(['b']);
});

it('keeps an entry for at most an hour, whatever TTL it is given, and nothing for a TTL below 1', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    $cache->set('long', new IcapResponse(200), PHP_INT_MAX);
    $cache->set('none', new IcapResponse(200), 0);
    $cache->set('negative', new IcapResponse(200), -5);
    expect($cache->get('none'))->toBeNull();
    expect($cache->get('negative'))->toBeNull();

    $now = 1000 + 3599;
    expect($cache->get('long'))->not->toBeNull();
    $now = 1000 + 3600;
    expect($cache->get('long'))->toBeNull();
});

it('keeps an answer for the shortest of the TTL it is given, its own Options-TTL and an hour', function () {
    foreach (StoredEntryCases::lifetimes() as $case => [$fields, $ttl, $lifetime]) {
        $now = 1000;
        $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
            return $now;
        });

        $cache->set('svc', new IcapResponse(200, $fields), $ttl);

        $now = 1000 + $lifetime - 1;
        expect($cache->get('svc'))->not->toBeNull($case);
        $now = 1000 + $lifetime;
        expect($cache->get('svc'))->toBeNull($case);
    }
});

it('stores nothing it would not hand out again', function () {
    foreach (StoredEntryCases::notKept() as $case => $response) {
        $cache = new InMemoryOptionsCache();
        $cache->set('svc', $response, 60);

        expect($cache->get('svc'))->toBeNull($case);
        expect(memoryCacheHeld($cache))->toBe(0, $case);
    }

    // An entry is the bytes of its field names and values and of its body, at most 64 KiB, in at most 100 field values.
    $fields = [];
    for ($i = 0; $i < 100; $i++) {
        $fields["X-Field-$i"] = ['v'];
    }
    $cache = new InMemoryOptionsCache();
    $cache->set('body', new IcapResponse(200, ['X' => ['a']], str_repeat('b', 65_534)), 60);
    $cache->set('fields', new IcapResponse(200, $fields), 60);
    expect(strlen($cache->get('body')->body ?? ''))->toBe(65_534);
    expect($cache->get('fields'))->not->toBeNull();
});

it('treats a clock that went back as the end of the entry', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    });

    $cache->set('k', new IcapResponse(200), 10);
    $now = 999;
    expect($cache->get('k'))->toBeNull();
});

/*
 * what has ended is not kept. Before, an entry was dropped only when its own key was asked for again, so
 * the entries followed the number of services ever asked for.
 */
it('drops the entries that have ended when it stores one, so that it holds the live ones', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    }, maxEntries: 5000);

    for ($i = 0; $i < 1000; $i++) {
        $cache->set('key' . $i, new IcapResponse(200), 1);
    }
    expect(memoryCacheHeld($cache))->toBe(1000);

    $now = 1002;
    $cache->set('another', new IcapResponse(200), 1);

    expect(memoryCacheHeld($cache))->toBe(1);
    expect($cache->get('another'))->not->toBeNull();
});

it('holds at most its capacity and drops the entry that was stored first', function () {
    $cache = new InMemoryOptionsCache(maxEntries: 3);

    foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
        $cache->set($key, new IcapResponse(200, ['Service' => [$key]]), 60);
    }
    expect(memoryCacheHeld($cache))->toBe(3);
    expect($cache->get('a'))->toBeNull();
    expect($cache->get('b'))->toBeNull();
    foreach (['c', 'd', 'e'] as $key) {
        expect($cache->get($key)?->headers['Service'])->toBe([$key], $key);
    }

    // A key that is stored again is the newest, and storing it takes no other entry's place.
    $cache->set('d', new IcapResponse(200, ['Service' => ['d again']]), 60);
    expect(memoryCacheHeld($cache))->toBe(3);
    expect($cache->get('c'))->not->toBeNull();
    $cache->set('f', new IcapResponse(200, ['Service' => ['f']]), 60);
    expect($cache->get('c'))->toBeNull();
    expect($cache->get('d')?->headers['Service'])->toBe(['d again']);
    expect($cache->get('e'))->not->toBeNull();
    expect($cache->get('f'))->not->toBeNull();
});

it('stays flat under a stream of services that are asked for once, while the clock moves on', function () {
    $now = 1000;
    $cache = new InMemoryOptionsCache(clock: function () use (&$now): int {
        return $now;
    }, maxEntries: 500);

    $peak = 0;
    for ($i = 0; $i < 5000; $i++) {
        $cache->set('service-' . $i, new IcapResponse(200), 5);
        $now++;
        $peak = max($peak, memoryCacheHeld($cache));
    }

    // Each entry lives 5 seconds and one is stored per second.
    expect($peak)->toBeLessThanOrEqual(6);
});

it('holds 64 entries by default and refuses a capacity below 1', function () {
    $cache = new InMemoryOptionsCache();
    for ($i = 0; $i < 100; $i++) {
        $cache->set('service-' . $i, new IcapResponse(200), 3600);
    }
    expect(memoryCacheHeld($cache))->toBe(64);

    foreach ([0, -1] as $capacity) {
        expect(fn () => new InMemoryOptionsCache(maxEntries: $capacity))->toThrow(InvalidArgumentException::class);
    }
});

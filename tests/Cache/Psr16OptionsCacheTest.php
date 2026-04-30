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

use Ndrstmr\Icap\Cache\Psr16OptionsCache;
use Ndrstmr\Icap\DTO\IcapResponse;
use Psr\SimpleCache\CacheInterface;

it('delegates get to PSR-16 and reconstructs IcapResponse', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $response = new IcapResponse(200, ['Preview' => ['1024']]);
    $cache->set('host:1344/svc', $response, 60);

    $result = $cache->get('host:1344/svc');
    expect($result)->not->toBeNull()
        ->and($result?->statusCode)->toBe(200)
        ->and($result?->headers['Preview'])->toBe(['1024']);
});

it('returns null on cache miss', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    expect($cache->get('nonexistent'))->toBeNull();
});

it('delegates delete to PSR-16', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('k', new IcapResponse(200), 60);
    expect($cache->get('k'))->not->toBeNull();

    $cache->delete('k');
    expect($cache->get('k'))->toBeNull();
});

it('flushes all entries when ISTag changes', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('svc1', new IcapResponse(200), 60, 'istag-v1');
    $cache->set('svc2', new IcapResponse(204), 60, 'istag-v1');
    expect($cache->get('svc1'))->not->toBeNull();
    expect($cache->get('svc2'))->not->toBeNull();

    // New ISTag → both entries should be flushed.
    $cache->set('svc3', new IcapResponse(200), 60, 'istag-v2');
    expect($cache->get('svc1'))->toBeNull();
    expect($cache->get('svc2'))->toBeNull();
    expect($cache->get('svc3'))->not->toBeNull();
});

it('does not cache when TTL is zero or negative', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('k', new IcapResponse(200), 0);
    expect($cache->get('k'))->toBeNull();

    $cache->set('k', new IcapResponse(200), -5);
    expect($cache->get('k'))->toBeNull();
});

it('uses key prefix to namespace entries in the backing store', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'myapp.');

    $cache->set('svc', new IcapResponse(200), 60);

    // The inner store should have the prefixed key.
    expect($inner->has('myapp.svc'))->toBeTrue();
    expect($inner->has('svc'))->toBeFalse();
});

// --- In-memory PSR-16 implementation for testing ---

/**
 * Minimal PSR-16 implementation backed by an array. TTL is ignored
 * (entries never expire) — sufficient for unit tests.
 */
final class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->store[$key] ?? $default;
        }
        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
        }
        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}

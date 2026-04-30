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

use Ndrstmr\Icap\Cache\Psr6OptionsCache;
use Ndrstmr\Icap\DTO\IcapResponse;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

it('delegates get to PSR-6 and reconstructs IcapResponse', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $response = new IcapResponse(200, ['Preview' => ['1024']]);
    $cache->set('host:1344/svc', $response, 60);

    $result = $cache->get('host:1344/svc');
    expect($result)->not->toBeNull()
        ->and($result?->statusCode)->toBe(200)
        ->and($result?->headers['Preview'])->toBe(['1024']);
});

it('returns null on cache miss', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    expect($cache->get('nonexistent'))->toBeNull();
});

it('delegates delete to PSR-6', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('k', new IcapResponse(200), 60);
    expect($cache->get('k'))->not->toBeNull();

    $cache->delete('k');
    expect($cache->get('k'))->toBeNull();
});

it('flushes all tracked entries when ISTag changes', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

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
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('k', new IcapResponse(200), 0);
    expect($cache->get('k'))->toBeNull();

    $cache->set('k', new IcapResponse(200), -5);
    expect($cache->get('k'))->toBeNull();
});

it('uses key prefix to namespace entries in the backing pool', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'myapp.');

    $cache->set('svc', new IcapResponse(200), 60);

    expect($pool->hasItem('myapp.svc'))->toBeTrue();
    expect($pool->hasItem('svc'))->toBeFalse();
});

// --- In-memory PSR-6 implementation for testing ---

final class ArrayCacheItem implements CacheItemInterface
{
    private mixed $value = null;
    private bool $isHit;
    private ?int $ttl = null;

    public function __construct(
        private readonly string $key,
        mixed $value = null,
        bool $isHit = false,
    ) {
        $this->value = $value;
        $this->isHit = $isHit;
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->value;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->isHit;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;
        return $this;
    }

    #[\Override]
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    #[\Override]
    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time instanceof \DateInterval) {
            $this->ttl = (int) (new \DateTime())->add($time)->getTimestamp() - time();
        } else {
            $this->ttl = $time;
        }
        return $this;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }
}

final class ArrayCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, ArrayCacheItem> */
    private array $items = [];

    /** @var list<ArrayCacheItem> */
    private array $deferred = [];

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        if (isset($this->items[$key])) {
            return new ArrayCacheItem($key, $this->items[$key]->get(), true);
        }
        return new ArrayCacheItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }
        return $result;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    #[\Override]
    public function clear(): bool
    {
        $this->items = [];
        $this->deferred = [];
        return true;
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }
        return true;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        /** @var ArrayCacheItem $item */
        $this->items[$item->getKey()] = $item;
        return true;
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        /** @var ArrayCacheItem $item */
        $this->deferred[] = $item;
        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];
        return true;
    }
}

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

namespace Ndrstmr\Icap\Cache;

use Closure;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * OPTIONS-cache adapter that delegates to any PSR-6 (Cache Item Pool) implementation.
 *
 * One item per entry, in the form of {@see OptionsEntry}, with a lifetime of at most an hour; nothing else is stored, no
 * ISTag item and no list of keys, so that writers in several processes cannot lose or invalidate each other's entries.
 * What the pool returns is not trusted: a value that is not a whole, current entry for exactly this key is a miss, also
 * when the pool still holds it past its end. The contract and the limits are those of {@see OptionsCacheInterface}; the
 * number of items in a pool that many applications share is the pool's to bound. Entries of earlier versions are not
 * read and not deleted.
 *
 * Every key the pool sees is 64 hex digits from {@see OptionsCacheKey::backend()}, whatever key the caller passes, so
 * a host name, a service path or a prefix cannot break the PSR-6 rules for keys.
 *
 * A PSR-6 {@see CacheException} becomes an {@see IcapStorageException} with that exception as its cause; anything
 * else the pool throws passes unchanged.
 *
 * Requires `psr/cache` ^3.0 — listed in composer.json `suggest`.
 */
final class Psr6OptionsCache implements OptionsCacheInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string                $prefix separates adapters that share a pool, tenants for one; part of the hashed
     *                                      key, not put in front of it
     * @param (Closure(): int)|null $clock  injectable clock for deterministic tests; defaults to time()
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $prefix = '',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        try {
            $item = $this->pool->getItem($this->backendKey($key));
            if (!$item->isHit()) {
                return null;
            }

            $stored = $item->get();
        } catch (CacheException $e) {
            throw self::failed($e);
        }

        return OptionsEntry::fromStored($stored, $key, ($this->clock)())?->response;
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $entry = OptionsEntry::from($key, $response, $ttlSeconds, ($this->clock)());
        if ($entry === null) {
            return;
        }

        try {
            $item = $this->pool->getItem($this->backendKey($key));
            $item->set($entry->toArray());
            $item->expiresAfter($entry->lifetime());
            $this->pool->save($item);
        } catch (CacheException $e) {
            throw self::failed($e);
        }
    }

    #[\Override]
    public function delete(string $key): void
    {
        try {
            $this->pool->deleteItem($this->backendKey($key));
        } catch (CacheException $e) {
            throw self::failed($e);
        }
    }

    private static function failed(CacheException $cause): IcapStorageException
    {
        return new IcapStorageException('The OPTIONS cache pool failed.', 0, $cause);
    }

    private function backendKey(string $key): string
    {
        return OptionsCacheKey::backend($this->prefix, $key);
    }
}

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
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

/**
 * OPTIONS-cache adapter that delegates to any PSR-16 (Simple Cache) implementation.
 *
 * One item per entry, in the form of {@see OptionsEntry}, with a lifetime of at most an hour; nothing else is stored, no
 * ISTag item and no list of keys, so that writers in several processes cannot lose or invalidate each other's entries.
 * What the backend returns is not trusted: a value that is not a whole, current entry for exactly this key is a miss,
 * also when the backend still holds it past its end. The contract and the limits are those of
 * {@see OptionsCacheInterface}; the number of items in a backend that many applications share is the backend's to
 * bound. Entries of earlier versions are not read and not deleted.
 *
 * Every key the backend sees is 64 hex digits from {@see OptionsCacheKey::backend()}, whatever key the caller passes,
 * so a host name, a service path or a prefix cannot break the PSR-16 rules for keys.
 *
 * A PSR-16 {@see CacheException} becomes an {@see IcapStorageException} with that exception as its cause; anything
 * else the backend throws passes unchanged.
 *
 * Requires `psr/simple-cache` ^3.0 — listed in composer.json `suggest`.
 */
final class Psr16OptionsCache implements OptionsCacheInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string                $prefix separates adapters that share a backend, tenants for one; part of the
     *                                      hashed key, not put in front of it
     * @param (Closure(): int)|null $clock  injectable clock for deterministic tests; defaults to time()
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = '',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        try {
            $stored = $this->cache->get($this->backendKey($key));
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
            $this->cache->set($this->backendKey($key), $entry->toArray(), $entry->lifetime());
        } catch (CacheException $e) {
            throw self::failed($e);
        }
    }

    #[\Override]
    public function delete(string $key): void
    {
        try {
            $this->cache->delete($this->backendKey($key));
        } catch (CacheException $e) {
            throw self::failed($e);
        }
    }

    private static function failed(CacheException $cause): IcapStorageException
    {
        return new IcapStorageException('The OPTIONS cache backend failed.', 0, $cause);
    }

    private function backendKey(string $key): string
    {
        return OptionsCacheKey::backend($this->prefix, $key);
    }
}

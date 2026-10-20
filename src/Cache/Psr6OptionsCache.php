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

use Ndrstmr\Icap\DTO\IcapResponse;
use Psr\Cache\CacheItemPoolInterface;

/**
 * OPTIONS-cache adapter that delegates to any PSR-6
 * (Cache Item Pool) implementation.
 *
 * The adapter stores each {@see IcapResponse} as a serializable array
 * in the backing pool. ISTag tracking uses two meta entries (the last
 * ISTag and the keys of the entries) so the flush-on-change behaviour
 * works across processes (unlike {@see InMemoryOptionsCache}, which is
 * process-local).
 *
 * Every key the pool sees is 64 hex digits from
 * {@see OptionsCacheKey::backend()}, whatever key the caller passes: the
 * PSR-6 rules for keys cannot be broken by a host name, a service path or
 * a prefix. Entries of an earlier key scheme are not read and not deleted.
 *
 * Requires `psr/cache` ^3.0 — listed in composer.json `suggest`.
 */
final class Psr6OptionsCache implements OptionsCacheInterface
{
    /**
     * @param string $prefix separates adapters that share a pool; part of the
     *                       hashed key, not put in front of it
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $prefix = '',
    ) {
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $item = $this->pool->getItem($this->backendKey(OptionsCacheKey::DATA, $key));
        if (!$item->isHit()) {
            return null;
        }

        /** @var array{statusCode: int, headers: array<string, list<string>>, body: string} $data */
        $data = $item->get();

        return new IcapResponse(
            $data['statusCode'],
            $data['headers'],
            $data['body'],
        );
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        if ($istag !== null) {
            $this->handleIstagChange($istag);
        }

        $data = [
            'statusCode' => $response->statusCode,
            'headers'    => $response->headers,
            'body'       => $response->body,
        ];

        $item = $this->pool->getItem($this->backendKey(OptionsCacheKey::DATA, $key));
        $item->set($data);
        $item->expiresAfter($ttlSeconds);
        $this->pool->save($item);

        $this->trackKey($key);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->pool->deleteItem($this->backendKey(OptionsCacheKey::DATA, $key));
        $this->untrackKey($key);
    }

    /**
     * When the ISTag changes, flush all previously tracked entries.
     */
    private function handleIstagChange(string $istag): void
    {
        $istagItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::ISTAG));

        if ($istagItem->isHit()) {
            /** @var string $stored */
            $stored = $istagItem->get();
            if ($stored !== $istag) {
                // ISTag changed — flush all tracked entries.
                $keysItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::REGISTRY));
                /** @var list<string> $keys */
                $keys = $keysItem->isHit() ? $keysItem->get() : [];
                foreach ($keys as $trackedKey) {
                    $this->pool->deleteItem($this->backendKey(OptionsCacheKey::DATA, $trackedKey));
                }
                $this->pool->deleteItem($this->backendKey(OptionsCacheKey::REGISTRY));
            }
        }

        // Always update to the latest ISTag.
        $item = $this->pool->getItem($this->backendKey(OptionsCacheKey::ISTAG));
        $item->set($istag);
        $this->pool->save($item);
    }

    private function trackKey(string $key): void
    {
        $keysItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::REGISTRY));
        /** @var list<string> $keys */
        $keys = $keysItem->isHit() ? $keysItem->get() : [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $newItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::REGISTRY));
            $newItem->set($keys);
            $this->pool->save($newItem);
        }
    }

    private function untrackKey(string $key): void
    {
        $keysItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::REGISTRY));
        /** @var list<string> $keys */
        $keys = $keysItem->isHit() ? $keysItem->get() : [];
        $keys = array_values(array_filter($keys, static fn (string $k): bool => $k !== $key));
        $newItem = $this->pool->getItem($this->backendKey(OptionsCacheKey::REGISTRY));
        $newItem->set($keys);
        $this->pool->save($newItem);
    }

    private function backendKey(string $role, string $key = ''): string
    {
        return OptionsCacheKey::backend($this->prefix, $role, $key);
    }
}

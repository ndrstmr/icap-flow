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
 * in the backing pool. ISTag tracking uses a dedicated meta-key so
 * the flush-on-change behaviour works across processes (unlike
 * {@see InMemoryOptionsCache}, which is process-local).
 *
 * Requires `psr/cache` ^3.0 — listed in composer.json `suggest`.
 */
final class Psr6OptionsCache implements OptionsCacheInterface
{
    private const string ISTAG_META_KEY = '__icap_istag';
    private const string KEYS_META_KEY = '__icap_keys';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly string $prefix = '',
    ) {
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $item = $this->pool->getItem($this->prefix . $key);
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

        $item = $this->pool->getItem($this->prefix . $key);
        $item->set($data);
        $item->expiresAfter($ttlSeconds);
        $this->pool->save($item);

        $this->trackKey($key);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->pool->deleteItem($this->prefix . $key);
        $this->untrackKey($key);
    }

    /**
     * When the ISTag changes, flush all previously tracked entries.
     */
    private function handleIstagChange(string $istag): void
    {
        $istagItem = $this->pool->getItem($this->prefix . self::ISTAG_META_KEY);

        if ($istagItem->isHit()) {
            /** @var string $stored */
            $stored = $istagItem->get();
            if ($stored !== $istag) {
                // ISTag changed — flush all tracked entries.
                $keysItem = $this->pool->getItem($this->prefix . self::KEYS_META_KEY);
                /** @var list<string> $keys */
                $keys = $keysItem->isHit() ? $keysItem->get() : [];
                foreach ($keys as $trackedKey) {
                    $this->pool->deleteItem($this->prefix . $trackedKey);
                }
                $this->pool->deleteItem($this->prefix . self::KEYS_META_KEY);
            }
        }

        // Always update to the latest ISTag.
        $item = $this->pool->getItem($this->prefix . self::ISTAG_META_KEY);
        $item->set($istag);
        $this->pool->save($item);
    }

    private function trackKey(string $key): void
    {
        $keysItem = $this->pool->getItem($this->prefix . self::KEYS_META_KEY);
        /** @var list<string> $keys */
        $keys = $keysItem->isHit() ? $keysItem->get() : [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $newItem = $this->pool->getItem($this->prefix . self::KEYS_META_KEY);
            $newItem->set($keys);
            $this->pool->save($newItem);
        }
    }

    private function untrackKey(string $key): void
    {
        $keysItem = $this->pool->getItem($this->prefix . self::KEYS_META_KEY);
        /** @var list<string> $keys */
        $keys = $keysItem->isHit() ? $keysItem->get() : [];
        $keys = array_values(array_filter($keys, static fn (string $k): bool => $k !== $key));
        $newItem = $this->pool->getItem($this->prefix . self::KEYS_META_KEY);
        $newItem->set($keys);
        $this->pool->save($newItem);
    }
}

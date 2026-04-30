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
use Psr\SimpleCache\CacheInterface;

/**
 * OPTIONS-cache adapter that delegates to any PSR-16 (Simple Cache)
 * implementation.
 *
 * The adapter stores each {@see IcapResponse} as a serializable array
 * in the backing store. ISTag tracking uses a dedicated meta-key so
 * the flush-on-change behaviour works across processes (unlike
 * {@see InMemoryOptionsCache}, which is process-local).
 *
 * Requires `psr/simple-cache` ^3.0 — listed in composer.json `suggest`.
 */
final class Psr16OptionsCache implements OptionsCacheInterface
{
    private const string ISTAG_META_KEY = '__icap_istag';
    private const string KEYS_META_KEY = '__icap_keys';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = '',
    ) {
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        /** @var array{statusCode: int, headers: array<string, list<string>>, body: string}|null $data */
        $data = $this->cache->get($this->prefix . $key);
        if ($data === null) {
            return null;
        }

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

        $this->cache->set($this->prefix . $key, $data, $ttlSeconds);
        $this->trackKey($key);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->cache->delete($this->prefix . $key);
        $this->untrackKey($key);
    }

    /**
     * When the ISTag changes, flush all previously tracked entries.
     */
    private function handleIstagChange(string $istag): void
    {
        /** @var string|null $stored */
        $stored = $this->cache->get($this->prefix . self::ISTAG_META_KEY);

        if ($stored !== null && $stored !== $istag) {
            // ISTag changed — flush all tracked entries.
            /** @var list<string> $keys */
            $keys = $this->cache->get($this->prefix . self::KEYS_META_KEY) ?? [];
            foreach ($keys as $trackedKey) {
                $this->cache->delete($this->prefix . $trackedKey);
            }
            $this->cache->delete($this->prefix . self::KEYS_META_KEY);
        }

        // Always update to the latest ISTag (no TTL — lives as long as
        // the cache backend keeps it, which is fine because we only use
        // it for comparison).
        $this->cache->set($this->prefix . self::ISTAG_META_KEY, $istag);
    }

    private function trackKey(string $key): void
    {
        /** @var list<string> $keys */
        $keys = $this->cache->get($this->prefix . self::KEYS_META_KEY) ?? [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->cache->set($this->prefix . self::KEYS_META_KEY, $keys);
        }
    }

    private function untrackKey(string $key): void
    {
        /** @var list<string> $keys */
        $keys = $this->cache->get($this->prefix . self::KEYS_META_KEY) ?? [];
        $keys = array_values(array_filter($keys, static fn (string $k): bool => $k !== $key));
        $this->cache->set($this->prefix . self::KEYS_META_KEY, $keys);
    }
}

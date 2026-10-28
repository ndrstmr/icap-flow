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

/**
 * Process-local OPTIONS-response cache.
 *
 * The default implementation. Sufficient for long-running async
 * workers (Symfony Messenger consumers, RoadRunner workers, php-fpm
 * with pre-warmed bytecode caches that share state across requests
 * — note: vanilla php-fpm does NOT share state, use APCu or Redis
 * for that).
 *
 * It holds at most `$maxEntries` entries: what has ended is dropped whenever an entry is stored, and when the cache is
 * full the entry that was stored first makes room. An entry stands for one service on one endpoint with one TLS
 * profile and holds at most 64 KiB of payload (field names, values and body) in at most 100 field values. The default
 * of 64 entries is 4 MiB of payload at most, plus the arrays and strings around at most 6,400 values, and far more than
 * a process talks to.
 *
 * Not safe across processes. Implement {@see OptionsCacheInterface}
 * against your shared cache for cross-process deployments.
 */
final class InMemoryOptionsCache implements OptionsCacheInterface
{
    public const int DEFAULT_MAX_ENTRIES = 64;

    /** @var array<string, OptionsEntry> in the order the entries were stored */
    private array $entries = [];

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param (Closure(): int)|null $clock injectable clock for deterministic tests; defaults to time()
     *
     * @throws \InvalidArgumentException When the capacity is below 1
     */
    public function __construct(
        ?Closure $clock = null,
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('The capacity of the cache must be at least 1.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if (!$entry->isLiveAt(($this->clock)())) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry->response;
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        $now = ($this->clock)();
        $entry = OptionsEntry::from($key, $response, $ttlSeconds, $now);
        if ($entry === null) {
            return;
        }

        foreach ($this->entries as $storedKey => $stored) {
            if (!$stored->isLiveAt($now)) {
                unset($this->entries[$storedKey]);
            }
        }
        // The key moves to the end of the line, and a full cache gives up its oldest entry.
        unset($this->entries[$key]);
        while (count($this->entries) >= $this->maxEntries && ($oldest = array_key_first($this->entries)) !== null) {
            unset($this->entries[$oldest]);
        }
        $this->entries[$key] = $entry;
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}

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

/**
 * Process-local OPTIONS-response cache.
 *
 * The default implementation. Sufficient for long-running async
 * workers (Symfony Messenger consumers, RoadRunner workers, php-fpm
 * with pre-warmed bytecode caches that share state across requests
 * — note: vanilla php-fpm does NOT share state, use APCu or Redis
 * for that).
 *
 * Not safe across processes. Implement {@see OptionsCacheInterface}
 * against your shared cache for cross-process deployments.
 */
final class InMemoryOptionsCache implements OptionsCacheInterface
{
    /** @var array<string, array{response: IcapResponse, expiresAt: int}> */
    private array $entries = [];

    /**
     * Test seam — lets unit tests advance the cache's notion of "now"
     * past a stored entry's TTL without sleeping.
     */
    private int $clockOffsetSeconds = 0;

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($this->now() >= $entry['expiresAt']) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry['response'];
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $this->entries[$key] = [
            'response'  => $response,
            'expiresAt' => $this->now() + $ttlSeconds,
        ];
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    /**
     * Advance the cache's notion of "now" by $seconds. Strictly for
     * tests — production code should use a real clock.
     */
    public function advanceClockForTesting(int $seconds): void
    {
        $this->clockOffsetSeconds += $seconds;
    }

    private function now(): int
    {
        return time() + $this->clockOffsetSeconds;
    }
}

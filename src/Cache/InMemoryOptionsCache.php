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
 * Not safe across processes. Implement {@see OptionsCacheInterface}
 * against your shared cache for cross-process deployments.
 */
final class InMemoryOptionsCache implements OptionsCacheInterface
{
    /** @var array<string, array{response: IcapResponse, expiresAt: int, istag: ?string}> */
    private array $entries = [];

    private ?string $lastKnownIstag = null;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param (Closure(): int)|null $clock injectable clock for deterministic tests; defaults to time()
     */
    public function __construct(
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    #[\Override]
    public function get(string $key): ?IcapResponse
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if (($this->clock)() >= $entry['expiresAt']) {
            unset($this->entries[$key]);
            return null;
        }

        // ISTag drift: if a newer ISTag has been observed globally,
        // entries stored under an older ISTag are stale.
        if (
            $this->lastKnownIstag !== null
            && $entry['istag'] !== null
            && $entry['istag'] !== $this->lastKnownIstag
        ) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry['response'];
    }

    #[\Override]
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        // When the ISTag changes, all previously cached entries are
        // potentially stale (the server updated its configuration or
        // signature database). Flush them.
        if ($istag !== null && $this->lastKnownIstag !== null && $istag !== $this->lastKnownIstag) {
            $this->entries = [];
        }

        if ($istag !== null) {
            $this->lastKnownIstag = $istag;
        }

        $this->entries[$key] = [
            'response'  => $response,
            'expiresAt' => ($this->clock)() + $ttlSeconds,
            'istag'     => $istag ?? $this->lastKnownIstag,
        ];
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}

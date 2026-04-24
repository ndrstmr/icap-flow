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
 * Storage for cached ICAP OPTIONS responses (RFC 3507 §4.10.2).
 *
 * The library ships a default in-memory implementation
 * ({@see InMemoryOptionsCache}); production deployments that want
 * cross-process caching can implement this interface against
 * Redis / APCu / file system / PSR-16 adapter / etc.
 *
 * Implementations MUST honour the TTL passed to {@see set()} and
 * return null from {@see get()} once the entry has expired.
 */
interface OptionsCacheInterface
{
    /**
     * Return the cached response for $key, or null on cache miss
     * (entry absent or expired).
     */
    public function get(string $key): ?IcapResponse;

    /**
     * Store $response under $key for at most $ttlSeconds. A value
     * <= 0 means "do not cache".
     */
    public function set(string $key, IcapResponse $response, int $ttlSeconds): void;

    /**
     * Remove the cached entry for $key. Idempotent.
     */
    public function delete(string $key): void;
}

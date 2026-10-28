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
 * The library ships a process-local implementation ({@see InMemoryOptionsCache}) and adapters for PSR-6 and PSR-16
 * ({@see Psr6OptionsCache}, {@see Psr16OptionsCache}) for caches that processes share.
 *
 * The contract:
 *  - An entry is valid for the `$ttlSeconds` of {@see set()}, counted from when it is stored, for at most the
 *    `Options-TTL` of the response if it has one, and for at most {@see MAX_TTL_SECONDS}. Reading never extends it;
 *    {@see get()} returns null once it ended. A change of the service
 *    (its ISTag, `Preview`, `Allow`) shows when the entry ends at the latest, which is the only consistency promised:
 *    nothing invalidates an entry earlier, and processes that share a backend are not told. Writers of one key replace
 *    the entry as a whole; the last one wins.
 *  - The client stores a response only if it has status 200, fits a budget (64 KiB of fields and body in at most 100
 *    field values) and does not contradict itself (`Options-TTL`, `ISTag`, `Preview`). It uses what {@see get()}
 *    returns only if that passes the same check and the limits of the client (`maxResponseSize`, `maxHeaderCount`,
 *    `maxHeaderLineLength`) would have taken it from the server. An entry that is none, however a custom cache came
 *    by it, is a miss.
 *  - The ISTag (RFC 3507 §4.7) belongs to the service that `$key` names. It never invalidates the entry of another key,
 *    and an implementation must not use it for that.
 *  - The key names the endpoint, the service (path and query) and the TLS profile of the client, so that a plain and
 *    a TLS client, or clients with other TLS settings, do not share an entry. It does not know a tenant, a proxy or
 *    anything else your transport does: give each security domain its own cache, or its own prefix with the PSR
 *    adapters.
 *  - How many entries a cache holds is the cache's to bound ({@see InMemoryOptionsCache} does; the PSR adapters bound
 *    the lifetime and size of an entry, and leave the total to the backend).
 *
 * A failure of the backend is reported as an {@see \Ndrstmr\Icap\Exception\IcapStorageException} with the cause as its
 * previous exception, as the PSR adapters do; anything else passes unchanged.
 *
 * The client names an entry with a key of 64 hex digits ({@see OptionsCacheKey::forService()}). Treat the key as
 * opaque: it holds nothing a PSR-6 or PSR-16 backend, a file name or a Redis key space could refuse.
 */
interface OptionsCacheInterface
{
    /**
     * The longest time in seconds that an entry is valid, whatever the server says: its `Options-TTL` can only shorten
     * it. An hour is the value of the example in RFC 3507 §4.10.2 and the longest a changed service goes unnoticed.
     */
    public const int MAX_TTL_SECONDS = 3600;

    /**
     * Return the cached response for $key, or null on cache miss
     * (entry absent or expired).
     */
    public function get(string $key): ?IcapResponse;

    /**
     * Store $response under $key for at most $ttlSeconds, and never for longer than its `Options-TTL` or
     * {@see MAX_TTL_SECONDS}. A value <= 0 means "do not cache".
     *
     * $istag is the ISTag of the service $key names, if the response has one. It informs; it invalidates nothing.
     */
    public function set(string $key, IcapResponse $response, int $ttlSeconds, ?string $istag = null): void;

    /**
     * Remove the cached entry for $key. Idempotent.
     */
    public function delete(string $key): void;
}

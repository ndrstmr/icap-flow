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
use Ndrstmr\Icap\HeaderFields;

/**
 * What the OPTIONS caches keep: one OPTIONS response with status 200, the key it was stored for and the time it
 * is valid in. All three caches use this class, so that what one stores another reads, and so that what comes back
 * from a backend is checked before it becomes a response.
 *
 * A response is kept if it has status 200, no body that was discarded, field names that are tokens, each with
 * values without control characters, at most {@see MAX_FIELDS} field values and {@see MAX_BYTES} bytes in names, values
 * and body together, and fields that do not contradict each other: at most one distinct `Preview` that is a decimal,
 * `ISTag` and `Options-TTL`, the latter a decimal above 0, and no header section in its `Encapsulated` layout. It is
 * valid for at most the seconds of its own `Options-TTL`, and for at most {@see OptionsCacheInterface::MAX_TTL_SECONDS},
 * from the time it was stored.
 *
 * @internal
 */
final readonly class OptionsEntry
{
    /** The version of the stored form; a stored value of another version is no entry. */
    private const int VERSION = 1;

    /**
     * Bytes in field names, field values and body together: the payload an entry holds. The reference service answers
     * in 289 bytes; this leaves room for a vendor with a longer answer and bounds what a backend that others write to
     * can make the client hold. The memory of an entry is its payload plus the arrays and strings around at most
     * {@see MAX_FIELDS} values.
     */
    public const int MAX_BYTES = 65_536;

    /**
     * Field values, as many as the default header limit of {@see \Ndrstmr\Icap\Config} allows. A field name has at least
     * one value, so there are no more names than that either.
     */
    public const int MAX_FIELDS = 100;

    public function __construct(
        public string $key,
        public IcapResponse $response,
        public int $storedAt,
        public int $expiresAt,
    ) {
    }

    /**
     * The entry for a response stored at $now for $ttlSeconds, at most the `Options-TTL` of the response and an hour, or
     * null if the response is not one to keep or the TTL leaves no time.
     */
    public static function from(string $key, IcapResponse $response, int $ttlSeconds, int $now): ?self
    {
        // A response that is not kept has no time.
        $ttl = min($ttlSeconds, self::lifetimeOf($response) ?? 0);

        return $ttl > 0 ? new self($key, $response, $now, $now + $ttl) : null;
    }

    /**
     * The entry that a backend returned as $data for $key, or null if $data is anything else: not an array of exactly
     * this form and version, an entry of another key, outside its lifetime at $now (not yet stored, or ended), one that
     * was stored for longer than its own `Options-TTL`, or a response that is not kept.
     */
    public static function fromStored(mixed $data, string $key, int $now): ?self
    {
        if (!is_array($data) || count($data) !== 7) {
            return null;
        }
        $stored = $data['stored'] ?? null;
        $expires = $data['expires'] ?? null;
        $headers = $data['headers'] ?? null;
        $body = $data['body'] ?? null;
        if (
            ($data['v'] ?? null) !== self::VERSION
            || ($data['key'] ?? null) !== $key
            || ($data['status'] ?? null) !== 200
            || !is_int($stored) || !is_int($expires)
            || !is_array($headers) || !is_string($body)
            || $stored > $now || $now >= $expires
        ) {
            return null;
        }
        // The headers are checked as they were stored, before the response normalizes them into shape.
        $fields = self::checkedFields($headers, $body);
        if ($fields === null) {
            return null;
        }
        $limit = self::lifetimeOfFields($fields);
        if ($limit === null || $expires - $stored > $limit) {
            return null;
        }

        return new self($key, new IcapResponse(200, $fields, $body), $stored, $expires);
    }

    /**
     * Whether a cache keeps this response and hands it out again.
     */
    public static function keeps(IcapResponse $response): bool
    {
        return self::lifetimeOf($response) !== null;
    }

    /**
     * The seconds an answer may be kept by its `Options-TTL` (RFC 3507 §4.10.2): that number, at most
     * {@see OptionsCacheInterface::MAX_TTL_SECONDS}; 0 for no time. A value that is not a decimal is read as 0, never
     * cast. A server that sends none says that the answer does not expire; the client keeps such an answer for
     * no time, which is its own decision and no statement of the server.
     */
    public static function ttl(?string $optionsTtl): int
    {
        if ($optionsTtl === null || preg_match('/\A[0-9]+\z/', $optionsTtl) !== 1) {
            return 0;
        }

        $digits = ltrim($optionsTtl, '0');

        return strlen($digits) > strlen((string) OptionsCacheInterface::MAX_TTL_SECONDS)
            ? OptionsCacheInterface::MAX_TTL_SECONDS
            : min((int) $digits, OptionsCacheInterface::MAX_TTL_SECONDS);
    }

    public function isLiveAt(int $now): bool
    {
        return $this->storedAt <= $now && $now < $this->expiresAt;
    }

    /**
     * The seconds the entry is valid for from when it was stored.
     */
    public function lifetime(): int
    {
        return $this->expiresAt - $this->storedAt;
    }

    /**
     * The form in which the PSR adapters store the entry.
     *
     * @return array{v: int, key: string, stored: int, expires: int, status: int, headers: array<string, string[]>, body: string}
     */
    public function toArray(): array
    {
        return [
            'v'       => self::VERSION,
            'key'     => $this->key,
            'stored'  => $this->storedAt,
            'expires' => $this->expiresAt,
            'status'  => 200,
            'headers' => $this->response->headers,
            'body'    => $this->response->body,
        ];
    }

    /**
     * The most seconds a response may be kept, or null if it is not one to keep.
     */
    private static function lifetimeOf(IcapResponse $response): ?int
    {
        if ($response->statusCode !== 200 || $response->discardedBodyBytes !== null || $response->encapsulatedStatus !== null) {
            return null;
        }
        $fields = self::checkedFields($response->headers, $response->body);

        return $fields === null ? null : self::lifetimeOfFields($fields);
    }

    /**
     * The most seconds the fields let the answer be kept: its `Options-TTL` (at most an hour), or an hour if it states
     * none. Null if the fields contradict each other or are no value: a `Preview` or an `Options-TTL` that is not a
     * single decimal (the TTL above 0), or differing `ISTag` fields; or if the `Encapsulated` layout has a header
     * section, which the entry does not hold.
     *
     * @param array<string, list<string>> $fields
     */
    private static function lifetimeOfFields(array $fields): ?int
    {
        $preview = self::distinct($fields, 'Preview');
        $ttl = self::distinct($fields, 'Options-TTL');
        if (
            count($preview) > 1 || count($ttl) > 1 || count(self::distinct($fields, 'ISTag')) > 1
            || ($preview !== [] && preg_match('/\A[0-9]+\z/', $preview[0]) !== 1)
            || preg_match('/(?:\A|,)[ \t]*(?:req|res)-hdr[ \t]*=/i', implode(',', HeaderFields::values($fields, 'Encapsulated'))) === 1
        ) {
            return null;
        }
        if ($ttl === []) {
            return OptionsCacheInterface::MAX_TTL_SECONDS;
        }
        $seconds = self::ttl($ttl[0]);

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * The values of a field in any spelling, each once.
     *
     * @param array<string, list<string>> $fields
     *
     * @return list<string>
     */
    private static function distinct(array $fields, string $name): array
    {
        return array_values(array_unique(HeaderFields::values($fields, $name)));
    }

    /**
     * The fields if they are names that are tokens, each with one or more values that are text, within the budget; else
     * null. The budget is checked as each value is counted (its name is counted with it, since a name has a value) and
     * before the value is read for its text, so that the work and the memory of the check are bound by it whatever the
     * array holds.
     *
     * @param array<mixed> $headers
     *
     * @return array<string, list<string>>|null
     */
    private static function checkedFields(array $headers, string $body): ?array
    {
        $bytes = strlen($body);
        if ($bytes > self::MAX_BYTES) {
            return null;
        }

        $checked = [];
        $values = 0;
        foreach ($headers as $name => $fieldValues) {
            // A numeric field name is an int key in a PHP array.
            $name = (string) $name;
            $bytes += strlen($name);
            if (!is_array($fieldValues) || $fieldValues === [] || !array_is_list($fieldValues) || !HeaderFields::isToken($name)) {
                return null;
            }
            $list = [];
            foreach ($fieldValues as $value) {
                if (!is_string($value) || ++$values > self::MAX_FIELDS) {
                    return null;
                }
                $bytes += strlen($value);
                if ($bytes > self::MAX_BYTES || !HeaderFields::isText($value)) {
                    return null;
                }
                $list[] = $value;
            }
            $checked[$name] = $list;
        }

        return $checked;
    }
}

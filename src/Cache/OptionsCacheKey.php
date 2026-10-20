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

/**
 * The keys of the OPTIONS cache (M12).
 *
 * PSR-6 and PSR-16 guarantee a key only if it consists of A-Z, a-z, 0-9, "_"
 * and "." and has at most 64 characters, and they reserve {}()/\@: for
 * themselves. A key written as host:port/service breaks both rules, and a
 * prefix in front of a 64-character hash breaks the length. Every key here is
 * therefore the SHA-256 of its parts, each written with its length: parts that
 * run into each other (prefix "a" and key "bc", prefix "ab" and key "c") give
 * different keys, and whatever the parts contain, the key is 64 hex digits.
 * The first part names the scheme and its version, so a new scheme never reads
 * an entry of the old one.
 *
 * @internal
 */
final class OptionsCacheKey
{
    /** The response of a service. */
    public const string DATA = 'data';

    /** The ISTag the entries of an adapter were stored under. */
    public const string ISTAG = 'istag';

    /** The keys of the entries of an adapter, to flush them when the ISTag changes. */
    public const string REGISTRY = 'registry';

    /**
     * The key under which the client stores and finds the OPTIONS response of
     * a service. Host, port and service stay separate parts, in options() and
     * in the preview size lookup alike. It does not tell TLS identities or
     * tenants apart.
     */
    public static function forService(string $host, int $port, string $service): string
    {
        return self::digest('icap-flow/options/v1', $host, (string) $port, $service);
    }

    /**
     * The key an adapter gives its PSR backend for one of its entries. The
     * prefix and the role of the entry are parts of the hash: the prefix
     * separates adapters that share a backend without making the key longer,
     * and a caller's key can never land on the ISTag or the registry.
     *
     * @param string $role one of {@see DATA}, {@see ISTAG} and {@see REGISTRY}
     * @param string $key  the caller's key; empty for the ISTag and the registry
     */
    public static function backend(string $prefix, string $role, string $key = ''): string
    {
        return self::digest('icap-flow/options-cache/v1', $prefix, $role, $key);
    }

    private static function digest(string ...$parts): string
    {
        $input = '';
        foreach ($parts as $part) {
            $input .= strlen($part) . ':' . $part . ';';
        }

        return hash('sha256', $input);
    }
}

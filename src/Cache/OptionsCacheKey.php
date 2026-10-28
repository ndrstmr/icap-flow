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

use Amp\Socket\ClientTlsContext;

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
    /**
     * The key under which the client stores and finds the OPTIONS response of
     * a service. Host, port, service and security profile stay separate parts,
     * in options() and in the preview size lookup alike.
     *
     * The security profile is `plain`, or the TLS settings the context applies to the socket: a client with other
     * settings has another profile and never meets this client's entry in a shared cache. The settings are hashed, not
     * the object, so that processes with the same settings share an entry. The passphrase of a certificate is left
     * out: a secret does not belong in a key, and it does not tell one profile from another. What the key cannot see
     * (a tenant, a proxy of a custom transport) belongs in the cache or its prefix.
     */
    public static function forService(string $host, int $port, string $service, ?ClientTlsContext $tls = null): string
    {
        return self::digest('icap-flow/options/v2', $host, (string) $port, $service, self::profile($tls));
    }

    /**
     * The key an adapter gives its PSR backend for one of its entries. The
     * prefix is a part of the hash: it separates adapters that share a backend
     * without making the key longer.
     */
    public static function backend(string $prefix, string $key): string
    {
        return self::digest('icap-flow/options-cache/v2', $prefix, $key);
    }

    private static function profile(?ClientTlsContext $tls): string
    {
        if ($tls === null) {
            return 'plain';
        }

        $options = $tls->toStreamContextArray()['ssl'] ?? [];
        if (is_array($options)) {
            unset($options['passphrase']);
        }

        return 'tls:' . hash('sha256', serialize($options));
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

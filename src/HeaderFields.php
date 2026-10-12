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

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\Exception\IcapMalformedResponseException;

/**
 * Reads ICAP header fields by name, ignoring case (RFC 3507 §4.3 via
 * RFC 7230 §3.2).
 *
 * Header maps keep every name as the server spelled it, so one field can
 * sit under several keys ("X-Virus-Name", "x-virus-name"). Verdict,
 * preview, layout and capability reads go through this class, for parsed
 * and for cached responses alike, so the spelling never changes a verdict,
 * a preview size or a body layout.
 *
 * @internal
 */
final class HeaderFields
{
    /**
     * Parse header lines into a {@code name => list<value>} map. Honours
     * RFC 7230 §3.2.4 obsolete line folding: a line that starts with HTAB
     * or SP is treated as a continuation of the previous header value
     * (joined with a single space). c-icap uses this folding form for the
     * multi-line `X-Violations-Found` header defined in RFC 3507 §6.4.
     *
     * @param  list<string>            $lines
     * @return array<string, string[]>
     */
    public static function parse(array $lines): array
    {
        /** @var array<string, string[]> $headers */
        $headers = [];
        $lastName = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Continuation line — append to the previous header's last
            // value with a single SP, the canonical "unfolded" form.
            if ($lastName !== null && ($line[0] === " " || $line[0] === "\t")) {
                $lastIdx = count($headers[$lastName]) - 1;
                $headers[$lastName][$lastIdx] .= ' ' . trim($line);
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new IcapMalformedResponseException('Malformed header line: ' . $line);
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $headers[$name][] = $value;
            $lastName = $name;
        }

        return $headers;
    }

    /**
     * The first value of the first listed field that is present, or null.
     * Used for the vendor virus headers, so the scan verdict and the
     * preview verdict apply the same policy.
     *
     * @param array<string, string[]> $headers
     * @param list<string>            $names
     */
    public static function first(array $headers, array $names): ?string
    {
        foreach ($names as $name) {
            $values = self::values($headers, $name);
            if ($values !== []) {
                return $values[0];
            }
        }

        return null;
    }

    /**
     * The value of a field that may occur only once. Repeating the same
     * value is harmless; differing values leave the message ambiguous, so
     * it is rejected instead of being resolved by position or spelling.
     *
     * @param array<string, string[]> $headers
     *
     * @throws IcapMalformedResponseException When the field carries differing values
     */
    public static function single(array $headers, string $name): ?string
    {
        $values = array_values(array_unique(self::values($headers, $name)));
        if (count($values) > 1) {
            throw new IcapMalformedResponseException(
                sprintf('ICAP response carries conflicting %s header fields.', $name),
            );
        }

        return $values[0] ?? null;
    }

    /**
     * All values of a field across every spelling, in map order.
     *
     * @param  array<string, string[]> $headers
     * @return list<string>
     */
    private static function values(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as $field => $fieldValues) {
            // A numeric field name becomes an int key in a PHP array.
            if (strcasecmp((string) $field, $name) === 0) {
                foreach ($fieldValues as $value) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}

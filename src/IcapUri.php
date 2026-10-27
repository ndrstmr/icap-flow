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

/**
 * Builds and checks the URIs the client sends and connects to
 * (RFC 3507 §4.2 with the RFC 3986 syntax). Host, port, service path and
 * query stay separate components: a service can neither move the
 * authority nor carry a fragment, and an IPv6 literal gets its brackets.
 *
 * @internal
 */
final class IcapUri
{
    /** RFC 3986 §3.3 pchar: unreserved, pct-encoded, sub-delims, ":" and "@". */
    private const string PCHAR = '(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@]|%[0-9A-Fa-f]{2})';

    /** RFC 3986 §3.4 query with its leading "?", optional. */
    private const string QUERY = '(?:\?(?:' . self::PCHAR . '|[/?])*)?';

    /** An absolute path with an optional query; no fragment. */
    private const string SERVICE = '#^(?:/' . self::PCHAR . '*)+' . self::QUERY . '\z#';

    /** RFC 3986 §3.2.2 host: an IP literal in brackets or a reg-name, which covers IPv4 addresses. */
    private const string HOST = '(?:\[[0-9A-Fa-f:.]+\]|(?:[A-Za-z0-9\-._~!$&\'()*+,;=]|%[0-9A-Fa-f]{2})+)';

    /** icap[s]://host[:port], optional path and query; no user info, no fragment. */
    private const string ICAP = '#^icaps?://(?<authority>(?<host>' . self::HOST . ')(?::[0-9]+)?)'
        . '(?<service>(?:/' . self::PCHAR . '*)*' . self::QUERY . ')\z#i';

    /**
     * The ICAP URI of a service on the given server. The port is left out
     * when it is the ICAP default 1344.
     *
     * @throws \InvalidArgumentException When the service is not an absolute
     *         path with an optional query, the host is not a host name or an
     *         IP address, or host and port do not form a valid authority
     */
    public static function forService(string $host, int $port, string $service): string
    {
        self::assertService($service);

        $uri = 'icap://' . self::host($host) . ($port === 1344 ? '' : ':' . $port) . $service;
        self::authority($uri);

        return $uri;
    }

    /**
     * A service on its own: an absolute path with an optional query and no fragment.
     *
     * @throws \InvalidArgumentException When it is not
     */
    public static function assertService(string $service): void
    {
        // Without the leading slash the service would extend the host
        // ("icap://localhostevil"); "@" or "#" would turn part of it into
        // user info or a fragment.
        if (preg_match(self::SERVICE, $service) !== 1) {
            throw new \InvalidArgumentException(
                'Service must be an absolute path with an optional query and no fragment: '
                . var_export($service, true),
            );
        }
    }

    /**
     * The service a request URI names: its path and query, as they stand in the request line.
     *
     * @throws \InvalidArgumentException When the URI does not have the form
     *         icap[s]://host[:port]/path[?query]
     */
    public static function serviceOf(string $uri): string
    {
        return self::parts($uri)['service'];
    }

    /**
     * Check an ICAP request URI and return its authority, which is the
     * value of the Host header.
     *
     * @throws \InvalidArgumentException When the URI does not have the form
     *         icap[s]://host[:port]/path[?query]
     */
    public static function authority(string $uri): string
    {
        return self::parts($uri)['authority'];
    }

    /**
     * @return array{authority: string, service: string}
     *
     * @throws \InvalidArgumentException When the URI does not have the form icap[s]://host[:port]/path[?query]
     */
    private static function parts(string $uri): array
    {
        if (preg_match(self::ICAP, $uri, $parts) !== 1 || !self::isHost($parts['host'])) {
            throw new \InvalidArgumentException(
                'ICAP request URI must have the form icap://host[:port]/path[?query], without user info or fragment.',
            );
        }

        return ['authority' => $parts['authority'], 'service' => $parts['service']];
    }

    /**
     * The socket address of a server, tcp://host:port. The host passes the
     * same check as in {@see forService()}.
     *
     * @throws \InvalidArgumentException When the host is not a host name or
     *         an IP address
     */
    public static function tcp(string $host, int $port): string
    {
        return sprintf('tcp://%s:%d', self::host($host), $port);
    }

    /**
     * The configured host as it goes into a URI. It is checked on its own,
     * before anything is appended: "/", "?" or "#" in it would otherwise
     * turn the service into another path, a query or a fragment. An IPv6
     * literal gets its brackets, which a following port needs
     * (RFC 3986 §3.2.2).
     *
     * @throws \InvalidArgumentException When the host is not a host name or
     *         an IP address
     */
    private static function host(string $host): string
    {
        $uriHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        if (!self::isHost($uriHost)) {
            throw new \InvalidArgumentException(
                'ICAP server host must be a host name or an IP address: ' . var_export($host, true),
            );
        }

        return $uriHost;
    }

    /**
     * Whether the value is a host per RFC 3986 §3.2.2; a bracketed literal
     * must hold a valid IPv6 address.
     */
    private static function isHost(string $host): bool
    {
        return preg_match('#^' . self::HOST . '\z#', $host) === 1
            && (!str_starts_with($host, '[')
                || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
    }
}

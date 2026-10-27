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

use Amp\Socket\ClientTlsContext;

/**
 * Transport, timeout, security and DoS-limit configuration for the
 * ICAP clients.
 *
 * The constructor checks every value, and the withers and the factories
 * build their result through it: a value that cannot work is an
 * \InvalidArgumentException when the configuration is made, not an error
 * at the first request.
 */
final readonly class Config
{
    private const int DEFAULT_MAX_RESPONSE_SIZE = 10 * 1024 * 1024;
    private const int DEFAULT_MAX_HEADER_COUNT = 100;
    private const int DEFAULT_MAX_HEADER_LINE = 8192;

    /** The whole of one operation, in seconds: a defensive library default, not a statement about any deployment. */
    private const float DEFAULT_OPERATION_TIMEOUT = 60.0;

    /** @var list<string> */
    private array $virusFoundHeaders;

    /**
     * @param string                $host                Host name or IP address of the ICAP server (an IPv6 literal with
     *                                                   or without brackets), in the syntax of the URIs the client builds
     *                                                   from it; nothing is resolved here
     * @param int                   $port                TCP port from 1 to 65535, defaults to 1344 (11344 is the common TLS port)
     * @param float                 $socketTimeout       Timeout in seconds for establishing the connection (TCP); finite and above 0
     * @param float                 $streamTimeout       Timeout in seconds for reading/writing: the longest the response
     *                                                   takes to arrive once its first byte is in; for a write, with
     *                                                   the async transport the longest one write call takes, with the
     *                                                   synchronous transport the longest the peer may go without
     *                                                   taking a byte (every byte it takes starts that again, so only
     *                                                   $operationTimeout bounds the write in all); finite and 0 or
     *                                                   more, where 0 gives up at once and never waits without limit
     * @param string                $virusFoundHeader    Legacy single virus header (back-compat). The client now
     *                                                   consults a list; this value seeds it when
     *                                                   $virusFoundHeaders is null.
     * @param ClientTlsContext|null $tlsContext          When set, the async transport upgrades the connection to TLS (icaps://)
     * @param int                   $maxResponseSize     DoS ceiling for total bytes accepted from a single ICAP response; 1 or more
     * @param int                   $maxHeaderCount      DoS ceiling for total header lines in the ICAP head block; 1 or more
     * @param int                   $maxHeaderLineLength DoS ceiling for a single header line length (bytes); 1 or more
     * @param list<string>|null     $virusFoundHeaders   Ordered, non-empty list of vendor virus-name headers, each a
     *                                                   header name (a token); null falls back to [$virusFoundHeader].
     * @param float                 $operationTimeout    Seconds one operation may take in all: from the call of a public method
     *                                                   to its result, over every phase (cache, waiting for the pool,
     *                                                   connecting, TLS, upload, preview, continuation, answers) and, in
     *                                                   the retry decorator, every attempt and pause. A monotonic end time,
     *                                                   which no phase and no nested call extends; finite and above 0
     * @param float|null            $tlsHandshakeTimeout Seconds the TLS handshake may take; finite and above 0; null: `$streamTimeout`
     * @param float|null            $responseStartTimeout Seconds the server may take, after the request is completely sent,
     *                                                   to begin its answer (the time a scan needs); finite and above 0;
     *                                                   null: `$streamTimeout`
     *
     * @throws \InvalidArgumentException When a value cannot work: a host that is neither a host name nor an IP
     *         address, a port outside 1 to 65535, a socket timeout that is not finite and above 0, a stream
     *         timeout that is not finite and 0 or more, an operation, TLS handshake or response start timeout
     *         that is not finite and above 0, a limit below 1, or virus headers that are not a non-empty list
     *         of header names
     */
    public function __construct(
        public string $host,
        public int $port = 1344,
        private float $socketTimeout = 10.0,
        private float $streamTimeout = 10.0,
        string $virusFoundHeader = 'X-Virus-Name',
        private ?ClientTlsContext $tlsContext = null,
        private int $maxResponseSize = self::DEFAULT_MAX_RESPONSE_SIZE,
        private int $maxHeaderCount = self::DEFAULT_MAX_HEADER_COUNT,
        private int $maxHeaderLineLength = self::DEFAULT_MAX_HEADER_LINE,
        ?array $virusFoundHeaders = null,
        private float $operationTimeout = self::DEFAULT_OPERATION_TIMEOUT,
        private ?float $tlsHandshakeTimeout = null,
        private ?float $responseStartTimeout = null,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port must be between 1 and 65535, got: ' . $port);
        }
        // The rules of the ICAP URI and of the socket address: what fails there fails here, without a lookup.
        IcapUri::tcp($host, $port);
        // var_export() for the floats: PHP 8.5 warns when a NAN is coerced to a string.
        if (!is_finite($socketTimeout) || $socketTimeout <= 0.0) {
            throw new \InvalidArgumentException('socketTimeout must be a finite number of seconds above 0, got: ' . var_export($socketTimeout, true));
        }
        // 0 stays valid: the transports give up at once instead of waiting without limit.
        if (!is_finite($streamTimeout) || $streamTimeout < 0.0) {
            throw new \InvalidArgumentException('streamTimeout must be a finite number of seconds, 0 or more, got: ' . var_export($streamTimeout, true));
        }
        // 0 is no limit here: a total of 0 seconds could never finish, and never means waiting for ever.
        if (!is_finite($operationTimeout) || $operationTimeout <= 0.0) {
            throw new \InvalidArgumentException('operationTimeout must be a finite number of seconds above 0, got: ' . var_export($operationTimeout, true));
        }
        if ($tlsHandshakeTimeout !== null && (!is_finite($tlsHandshakeTimeout) || $tlsHandshakeTimeout <= 0.0)) {
            throw new \InvalidArgumentException('tlsHandshakeTimeout must be a finite number of seconds above 0 or null, got: ' . var_export($tlsHandshakeTimeout, true));
        }
        if ($responseStartTimeout !== null && (!is_finite($responseStartTimeout) || $responseStartTimeout <= 0.0)) {
            throw new \InvalidArgumentException('responseStartTimeout must be a finite number of seconds above 0 or null, got: ' . var_export($responseStartTimeout, true));
        }
        if ($maxResponseSize < 1) {
            throw new \InvalidArgumentException('maxResponseSize must be >= 1, got: ' . $maxResponseSize);
        }
        if ($maxHeaderCount < 1) {
            throw new \InvalidArgumentException('maxHeaderCount must be >= 1, got: ' . $maxHeaderCount);
        }
        if ($maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('maxHeaderLineLength must be >= 1, got: ' . $maxHeaderLineLength);
        }

        $this->virusFoundHeaders = self::checkedHeaderNames($virusFoundHeaders ?? [$virusFoundHeader]);
    }

    /**
     * The virus header names as an ordered list of tokens, or an error that
     * names what is wrong. The names come from callers no static analysis
     * checks, so every entry is tested, not just the first.
     *
     * @param  array<mixed>  $names
     * @return list<string>
     *
     * @throws \InvalidArgumentException When the names are not a non-empty list of tokens
     */
    private static function checkedHeaderNames(array $names): array
    {
        if ($names === [] || !array_is_list($names)) {
            throw new \InvalidArgumentException('virusFoundHeaders must be a non-empty list of header names.');
        }

        $checked = [];
        foreach ($names as $name) {
            if (!is_string($name) || !HeaderFields::isToken($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'virusFoundHeaders must contain only header names that are tokens (RFC 7230 §3.2.6), got: %s',
                    is_string($name) ? var_export($name, true) : get_debug_type($name),
                ));
            }
            $checked[] = $name;
        }

        return $checked;
    }

    public function getSocketTimeout(): float
    {
        return $this->socketTimeout;
    }

    public function getStreamTimeout(): float
    {
        return $this->streamTimeout;
    }

    /**
     * Seconds one operation may take in all, from the call of a public method to its result.
     */
    public function getOperationTimeout(): float
    {
        return $this->operationTimeout;
    }

    /**
     * Seconds the TLS handshake may take: its own setting, or the stream timeout, which has always bounded it.
     */
    public function getTlsHandshakeTimeout(): float
    {
        return $this->tlsHandshakeTimeout ?? $this->streamTimeout;
    }

    /**
     * Seconds the server may take, once the request is sent, to begin its answer: its own setting, or the
     * stream timeout.
     */
    public function getResponseStartTimeout(): float
    {
        return $this->responseStartTimeout ?? $this->streamTimeout;
    }

    /**
     * Legacy accessor — returns the first configured virus header.
     * Prefer {@see getVirusFoundHeaders()} for new code; this method
     * stays for back-compat with v1 callers.
     */
    public function getVirusFoundHeader(): string
    {
        return $this->virusFoundHeaders[0];
    }

    /**
     * Ordered list of ICAP headers inspected for infection signals.
     * The first header that is present in the server's response wins.
     * Different vendors use different headers — Trend Micro reports via
     * `X-Violations-Found`, ISS Proventia via `X-Infection-Found`, Symantec
     * via `X-Virus-ID`. The list says what an infection looks like, not
     * whether a file was scanned: for c-icap `virus_scan` (which reports via
     * `X-Infection-Found` and `X-Violations-Found`) use the verified mode,
     * {@see \Ndrstmr\Icap\CicapVerdict}, which does not use this list.
     *
     * @return list<string>
     */
    public function getVirusFoundHeaders(): array
    {
        return $this->virusFoundHeaders;
    }

    public function withVirusFoundHeader(string $headerName): self
    {
        return $this->withVirusFoundHeaders([$headerName]);
    }

    /**
     * @param list<string> $headerNames
     *
     * @throws \InvalidArgumentException When the names are not a non-empty list of header names
     */
    public function withVirusFoundHeaders(array $headerNames): self
    {
        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            tlsContext: $this->tlsContext,
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            virusFoundHeaders: $headerNames,
            operationTimeout: $this->operationTimeout,
            tlsHandshakeTimeout: $this->tlsHandshakeTimeout,
            responseStartTimeout: $this->responseStartTimeout,
        );
    }

    public function getTlsContext(): ?ClientTlsContext
    {
        return $this->tlsContext;
    }

    /**
     * Return a new instance with the supplied TLS context. Pass an
     * amphp/socket {@see ClientTlsContext}; the async transport will
     * upgrade the connection via `Socket\connectTls()`.
     */
    public function withTlsContext(?ClientTlsContext $tlsContext): self
    {
        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            tlsContext: $tlsContext,
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            virusFoundHeaders: $this->virusFoundHeaders,
            operationTimeout: $this->operationTimeout,
            tlsHandshakeTimeout: $this->tlsHandshakeTimeout,
            responseStartTimeout: $this->responseStartTimeout,
        );
    }

    /**
     * Return a new instance with the operation, TLS handshake and response start timeouts overridden. Passing
     * null leaves a timeout at its current value.
     *
     * @throws \InvalidArgumentException When a timeout is not finite and above 0
     */
    public function withTimeouts(
        ?float $operationTimeout = null,
        ?float $tlsHandshakeTimeout = null,
        ?float $responseStartTimeout = null,
    ): self {
        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            tlsContext: $this->tlsContext,
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            virusFoundHeaders: $this->virusFoundHeaders,
            operationTimeout: $operationTimeout ?? $this->operationTimeout,
            tlsHandshakeTimeout: $tlsHandshakeTimeout ?? $this->tlsHandshakeTimeout,
            responseStartTimeout: $responseStartTimeout ?? $this->responseStartTimeout,
        );
    }

    public function getMaxResponseSize(): int
    {
        return $this->maxResponseSize;
    }

    public function getMaxHeaderCount(): int
    {
        return $this->maxHeaderCount;
    }

    public function getMaxHeaderLineLength(): int
    {
        return $this->maxHeaderLineLength;
    }

    /**
     * Return a new instance with DoS limits overridden. Passing null
     * leaves a limit at its current value.
     *
     * @throws \InvalidArgumentException When a limit is below 1
     */
    public function withLimits(
        ?int $maxResponseSize = null,
        ?int $maxHeaderCount = null,
        ?int $maxHeaderLineLength = null,
    ): self {
        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            tlsContext: $this->tlsContext,
            maxResponseSize: $maxResponseSize ?? $this->maxResponseSize,
            maxHeaderCount: $maxHeaderCount ?? $this->maxHeaderCount,
            maxHeaderLineLength: $maxHeaderLineLength ?? $this->maxHeaderLineLength,
            virusFoundHeaders: $this->virusFoundHeaders,
            operationTimeout: $this->operationTimeout,
            tlsHandshakeTimeout: $this->tlsHandshakeTimeout,
            responseStartTimeout: $this->responseStartTimeout,
        );
    }
}

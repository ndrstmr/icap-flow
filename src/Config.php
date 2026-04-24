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
 */
final readonly class Config
{
    private const int DEFAULT_MAX_RESPONSE_SIZE = 10 * 1024 * 1024;
    private const int DEFAULT_MAX_HEADER_COUNT = 100;
    private const int DEFAULT_MAX_HEADER_LINE = 8192;

    /** @var list<string> */
    private array $virusFoundHeaders;

    /**
     * @param string                $host                Hostname or IP of the ICAP server
     * @param int                   $port                TCP port, defaults to 1344 (11344 is the common TLS port)
     * @param float                 $socketTimeout       Timeout in seconds for establishing the connection
     * @param float                 $streamTimeout       Timeout in seconds for reading/writing
     * @param string                $virusFoundHeader    Legacy single virus header (back-compat). The client now
     *                                                   consults a list; this value seeds it when
     *                                                   $virusFoundHeaders is null.
     * @param ClientTlsContext|null $tlsContext          When set, the async transport upgrades the connection to TLS (icaps://)
     * @param int                   $maxResponseSize     DoS ceiling for total bytes accepted from a single ICAP response
     * @param int                   $maxHeaderCount      DoS ceiling for total header lines in the ICAP head block
     * @param int                   $maxHeaderLineLength DoS ceiling for a single header line length (bytes)
     * @param list<string>|null     $virusFoundHeaders   Ordered list of vendor virus-name headers;
     *                                                   null falls back to [$virusFoundHeader].
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
    ) {
        $this->virusFoundHeaders = $virusFoundHeaders ?? [$virusFoundHeader];
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
     * Different vendors use different headers — c-icap / ClamAV use
     * `X-Virus-Name`; Trend Micro reports via `X-Violations-Found`,
     * ISS Proventia via `X-Infection-Found`, Symantec via `X-Virus-ID`.
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
     */
    public function withVirusFoundHeaders(array $headerNames): self
    {
        if ($headerNames === []) {
            throw new \InvalidArgumentException('virusFoundHeaders must contain at least one header name.');
        }

        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            virusFoundHeader: $headerNames[0],
            tlsContext: $this->tlsContext,
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            virusFoundHeaders: $headerNames,
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
            virusFoundHeader: $this->virusFoundHeaders[0],
            tlsContext: $tlsContext,
            maxResponseSize: $this->maxResponseSize,
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
            virusFoundHeaders: $this->virusFoundHeaders,
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
     */
    public function withLimits(
        ?int $maxResponseSize = null,
        ?int $maxHeaderCount = null,
        ?int $maxHeaderLineLength = null,
    ): self {
        if ($maxResponseSize !== null && $maxResponseSize < 1) {
            throw new \InvalidArgumentException('maxResponseSize must be >= 1, got: ' . $maxResponseSize);
        }
        if ($maxHeaderCount !== null && $maxHeaderCount < 1) {
            throw new \InvalidArgumentException('maxHeaderCount must be >= 1, got: ' . $maxHeaderCount);
        }
        if ($maxHeaderLineLength !== null && $maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('maxHeaderLineLength must be >= 1, got: ' . $maxHeaderLineLength);
        }

        return new self(
            host: $this->host,
            port: $this->port,
            socketTimeout: $this->socketTimeout,
            streamTimeout: $this->streamTimeout,
            virusFoundHeader: $this->virusFoundHeaders[0],
            tlsContext: $this->tlsContext,
            maxResponseSize: $maxResponseSize ?? $this->maxResponseSize,
            maxHeaderCount: $maxHeaderCount ?? $this->maxHeaderCount,
            maxHeaderLineLength: $maxHeaderLineLength ?? $this->maxHeaderLineLength,
            virusFoundHeaders: $this->virusFoundHeaders,
        );
    }
}

<?php

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

    /**
     * @param string                $host               Hostname or IP of the ICAP server
     * @param int                   $port               TCP port, defaults to 1344 (11344 is the common TLS port)
     * @param float                 $socketTimeout      Timeout in seconds for establishing the connection
     * @param float                 $streamTimeout      Timeout in seconds for reading/writing
     * @param string                $virusFoundHeader   Header name inspected for an infection signal (default X-Virus-Name)
     * @param ClientTlsContext|null $tlsContext         When set, the async transport upgrades the connection to TLS (icaps://)
     * @param int                   $maxResponseSize    DoS ceiling for total bytes accepted from a single ICAP response
     * @param int                   $maxHeaderCount     DoS ceiling for total header lines in the ICAP head block
     * @param int                   $maxHeaderLineLength DoS ceiling for a single header line length (bytes)
     */
    public function __construct(
        public string $host,
        public int $port = 1344,
        private float $socketTimeout = 10.0,
        private float $streamTimeout = 10.0,
        private string $virusFoundHeader = 'X-Virus-Name',
        private ?ClientTlsContext $tlsContext = null,
        private int $maxResponseSize = self::DEFAULT_MAX_RESPONSE_SIZE,
        private int $maxHeaderCount = self::DEFAULT_MAX_HEADER_COUNT,
        private int $maxHeaderLineLength = self::DEFAULT_MAX_HEADER_LINE,
    ) {
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
     * Header the ICAP server uses to report an infection. Defaults to
     * the de-facto standard `X-Virus-Name`; c-icap, ClamAV, Sophos and
     * Kaspersky use it verbatim.
     */
    public function getVirusFoundHeader(): string
    {
        return $this->virusFoundHeader;
    }

    public function withVirusFoundHeader(string $headerName): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->socketTimeout,
            $this->streamTimeout,
            $headerName,
            $this->tlsContext,
            $this->maxResponseSize,
            $this->maxHeaderCount,
            $this->maxHeaderLineLength,
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
            $this->host,
            $this->port,
            $this->socketTimeout,
            $this->streamTimeout,
            $this->virusFoundHeader,
            $tlsContext,
            $this->maxResponseSize,
            $this->maxHeaderCount,
            $this->maxHeaderLineLength,
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
            $this->host,
            $this->port,
            $this->socketTimeout,
            $this->streamTimeout,
            $this->virusFoundHeader,
            $this->tlsContext,
            $maxResponseSize ?? $this->maxResponseSize,
            $maxHeaderCount ?? $this->maxHeaderCount,
            $maxHeaderLineLength ?? $this->maxHeaderLineLength,
        );
    }
}

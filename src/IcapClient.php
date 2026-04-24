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

use Amp\Cancellation;
use Amp\Future;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapResponseException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core asynchronous ICAP client used by the synchronous wrapper.
 */
final class IcapClient implements IcapClientInterface
{
    private PreviewStrategyInterface $previewStrategy;
    private LoggerInterface $logger;

    public function __construct(
        private Config $config,
        private TransportInterface $transport,
        private RequestFormatterInterface $formatter,
        private ResponseParserInterface $parser,
        ?PreviewStrategyInterface $previewStrategy = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->previewStrategy = $previewStrategy ?? new DefaultPreviewStrategy();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Convenience factory for synchronous environments.
     */
    public static function forServer(string $host, int $port = 1344): self
    {
        $config = new Config($host, $port);
        return new self(
            $config,
            new SynchronousStreamTransport(),
            new RequestFormatter(),
            self::parserFor($config),
        );
    }

    /**
     * Factory using the default async transport.
     */
    public static function create(): self
    {
        $config = new Config('127.0.0.1');
        return new self(
            $config,
            new AsyncAmpTransport(),
            new RequestFormatter(),
            self::parserFor($config),
            new DefaultPreviewStrategy(),
        );
    }

    private static function parserFor(Config $config): ResponseParser
    {
        return new ResponseParser(
            maxHeaderCount: $config->getMaxHeaderCount(),
            maxHeaderLineLength: $config->getMaxHeaderLineLength(),
        );
    }

    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($request, $cancellation): ScanResult {
            $context = [
                'method' => $request->method,
                'uri'    => $request->uri,
                'host'   => $this->config->host,
                'port'   => $this->config->port,
            ];
            $this->logger->info('ICAP request started', $context);

            $response = null;
            try {
                $response = $this->executeRaw($request, $cancellation)->await();
                $result = $this->interpretResponse($response, $this->config);
            } catch (\Throwable $e) {
                $this->logger->warning('ICAP request failed', $context + [
                    'statusCode' => $response?->statusCode,
                    'exception'  => $e::class,
                    'message'    => $e->getMessage(),
                ]);
                throw $e;
            }

            $this->logger->info('ICAP request completed', $context + [
                'statusCode' => $response->statusCode,
                'infected'   => $result->isInfected(),
            ]);

            return $result;
        });

        return $future;
    }

    /**
     * Send the ICAP request and return the parsed {@see IcapResponse}
     * without the fail-secure status-code interpretation pass. Used by
     * the preview flow, where 100 Continue is a legitimate intermediate
     * response. External callers should prefer {@see request()}.
     *
     * @return Future<IcapResponse>
     */
    public function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        /** @var Future<IcapResponse> $future */
        $future = \Amp\async(function () use ($request, $cancellation): IcapResponse {
            $chunks = $this->formatter->format($request);
            $responseString = $this->transport->request($this->config, $chunks, $cancellation)->await();
            return $this->parser->parse($responseString);
        });

        return $future;
    }

    #[\Override]
    public function options(string $service, ?Cancellation $cancellation = null): Future
    {
        $uri = $this->buildServiceUri($service);
        $request = new IcapRequest('OPTIONS', $uri);
        return $this->request($request, $cancellation);
    }

    /**
     * Scan a local file via RESPMOD by wrapping it in a synthesized HTTP
     * response envelope (Content-Type: application/octet-stream,
     * Content-Length: <filesize>). The file contents are streamed into
     * the encapsulated body, so the full file never lives in memory.
     *
     * @throws \RuntimeException When the file cannot be opened
     */
    #[\Override]
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        // Validate first so injection attempts never reach the socket.
        $this->validateIcapHeaders($extraHeaders);
        $uri = $this->buildServiceUri($service);

        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open file: ' . $filePath);
        }
        $size = filesize($filePath);

        $httpResponse = new HttpResponse(
            statusCode: 200,
            headers: [
                'Content-Type'   => ['application/octet-stream'],
                'Content-Length' => [(string) ($size !== false ? $size : 0)],
            ],
            body: $stream,
        );

        $request = new IcapRequest(
            method: 'RESPMOD',
            uri: $uri,
            headers: $extraHeaders,
            encapsulatedResponse: $httpResponse,
        );

        return $this->request($request, $cancellation);
    }

    /**
     * Scan a local file via RESPMOD using a preview. The first
     * {@see $previewSize} bytes are sent along with the Preview /
     * Allow: 204 headers; if the file fits entirely within the preview
     * the request is terminated with `0; ieof\r\n\r\n` (RFC 3507 §4.5)
     * and no continuation round-trip is necessary.
     *
     * @throws \RuntimeException When the file cannot be opened
     */
    #[\Override]
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        int $previewSize = 1024,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        if ($previewSize < 1) {
            throw new \InvalidArgumentException('Preview size must be >= 1, got: ' . $previewSize);
        }
        $this->validateIcapHeaders($extraHeaders);

        /** @var int<1, max> $previewSize */
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($service, $filePath, $previewSize, $extraHeaders, $cancellation): ScanResult {
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new \RuntimeException('Unable to stat file: ' . $filePath);
            }
            $stream = fopen($filePath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open file: ' . $filePath);
            }

            $previewBytes = fread($stream, $previewSize);
            if ($previewBytes === false) {
                fclose($stream);
                throw new \RuntimeException('Unable to read preview: ' . $filePath);
            }

            $previewIsComplete = $fileSize <= $previewSize;

            $previewEnvelope = new HttpResponse(
                statusCode: 200,
                headers: [
                    'Content-Type'   => ['application/octet-stream'],
                    'Content-Length' => [(string) $fileSize],
                ],
                body: $previewBytes,
            );

            // Merge caller-supplied headers, but library-managed headers
            // MUST win: letting the caller override Preview/Allow would
            // put the protocol exchange into an inconsistent state.
            $headers = $this->mergeHeaders($extraHeaders, [
                'Preview' => [(string) $previewSize],
                'Allow'   => ['204'],
            ]);

            $previewRequest = new IcapRequest(
                method: 'RESPMOD',
                uri: $this->buildServiceUri($service),
                headers: $headers,
                encapsulatedResponse: $previewEnvelope,
                previewIsComplete: $previewIsComplete,
            );

            // Preview round: bypass interpretResponse() so a legitimate
            // 100 Continue from the server doesn't trip the fail-secure
            // guard that only applies outside preview context.
            $previewIcapResponse = $this->executeRaw($previewRequest, $cancellation)->await();

            if ($previewIsComplete) {
                fclose($stream);
                return $this->interpretResponse($previewIcapResponse, $this->config);
            }

            $decision = $this->previewStrategy->handlePreviewResponse($previewIcapResponse);

            if ($decision !== PreviewDecision::CONTINUE_SENDING) {
                fclose($stream);
                // The strategy has made the final call. Build a
                // ScanResult directly from its verdict rather than
                // running interpretResponse() over the preview-stage
                // status code (which is 100 in a typical abort path
                // and would otherwise trip the fail-secure guard).
                return new ScanResult(
                    isInfected: $decision === PreviewDecision::ABORT_INFECTED,
                    virusName: $decision === PreviewDecision::ABORT_INFECTED
                        ? $this->extractVirusName($previewIcapResponse, $this->config)
                        : null,
                    originalResponse: $previewIcapResponse,
                );
            }

            // The server wants the rest. Stream the full file in a fresh
            // RESPMOD; this is the pragmatic approximation of the
            // RFC-3507 §4.5 "continue after preview" flow on a persistent
            // connection — proper single-connection continuation is
            // tracked for a later milestone.
            rewind($stream);
            $fullResponse = new HttpResponse(
                statusCode: 200,
                headers: [
                    'Content-Type'   => ['application/octet-stream'],
                    'Content-Length' => [(string) $fileSize],
                ],
                body: $stream,
            );
            $fullRequest = new IcapRequest(
                method: 'RESPMOD',
                uri: $this->buildServiceUri($service),
                headers: $extraHeaders,
                encapsulatedResponse: $fullResponse,
            );

            try {
                return $this->request($fullRequest, $cancellation)->await();
            } finally {
                fclose($stream);
            }
        });

        return $future;
    }

    private function buildServiceUri(string $service): string
    {
        $this->validateServicePath($service);
        $host = $this->config->host;
        if ($this->config->port !== 1344) {
            $host .= ':' . $this->config->port;
        }
        return sprintf('icap://%s%s', $host, $service);
    }

    /**
     * Guard $service against header/URI injection. Finding H of the
     * consolidated review: user-controlled service paths can sneak
     * CR/LF into the request line and inject additional ICAP headers
     * before any wire byte has been written.
     *
     * RFC 3507 §4.2 allows only the abs_path production for the
     * service; we enforce a conservative subset (no controls, no
     * whitespace, no NUL) which is sufficient for every known ICAP
     * server while leaving segment separators like '/' untouched.
     */
    private function validateServicePath(string $service): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $service) === 1) {
            throw new \InvalidArgumentException(
                'Service path contains control characters, whitespace or NUL; refusing to build ICAP URI: ' . var_export($service, true),
            );
        }
    }

    /**
     * Map an ICAP response to a {@see ScanResult} or raise a typed
     * exception. Status-code handling follows RFC 3507 §4.3.3 and the
     * de-facto vendor conventions (§7 of the consolidated review).
     *
     * Security note (finding G): 100 Continue is NOT a finish state
     * and MUST NOT be mapped to a clean scan. Outside a preview the
     * 100 is a protocol error; inside a preview the caller handles it
     * via the {@see PreviewStrategyInterface} before this method sees
     * the response.
     */
    private function interpretResponse(IcapResponse $response, Config $config): ScanResult
    {
        $code = $response->statusCode;

        if ($code === 204) {
            return new ScanResult(false, null, $response);
        }

        if ($code === 200 || $code === 206) {
            // 206 Partial Content — some vendors return this when the
            // encapsulated response was modified but not fully rewritten
            // (RFC 3507 §4.3.3). Virus signalling is the same as 200.
            $virus = $this->extractVirusName($response, $config);
            if ($virus !== null) {
                return new ScanResult(true, $virus, $response);
            }
            return new ScanResult(false, null, $response);
        }

        if ($code === 100) {
            throw new IcapProtocolException(
                'ICAP 100 Continue is only valid during a preview exchange; received outside preview flow.',
                $code,
            );
        }

        if ($code >= 400 && $code < 500) {
            throw new IcapClientException(
                sprintf('ICAP client error (%d) — request rejected by server.', $code),
                $code,
            );
        }

        if ($code >= 500 && $code < 600) {
            throw new IcapServerException(
                sprintf('ICAP server error (%d) — server failed to service the request.', $code),
                $code,
            );
        }

        throw new IcapResponseException('Unexpected ICAP status: ' . $code, $code);
    }

    private function extractVirusName(IcapResponse $response, Config $config): ?string
    {
        foreach ($config->getVirusFoundHeaders() as $header) {
            if (isset($response->headers[$header][0])) {
                return $response->headers[$header][0];
            }
        }
        return null;
    }

    /**
     * Reject header names or values that contain CR/LF, NUL or would
     * otherwise break the wire format. Finding H, applied to
     * user-supplied ICAP headers in addition to the service path.
     *
     * @param array<string, string|string[]> $headers
     */
    private function validateIcapHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (preg_match('/[\x00-\x1F\x7F:]/', $name) === 1) {
                throw new \InvalidArgumentException(
                    'ICAP header name contains control characters or separator: ' . var_export($name, true),
                );
            }
            foreach ((array) $value as $v) {
                if (preg_match('/[\x00\r\n]/', $v) === 1) {
                    throw new \InvalidArgumentException(
                        sprintf('ICAP header %s carries a value with CR/LF/NUL; refusing to send.', $name),
                    );
                }
            }
        }
    }

    /**
     * Merge caller-supplied headers with library-managed headers. The
     * library-managed map always wins, keeping protocol-critical
     * headers (Preview, Allow, Encapsulated, Host) out of caller
     * control.
     *
     * @param  array<string, string|string[]> $caller
     * @param  array<string, string[]>        $managed
     * @return array<string, string[]>
     */
    private function mergeHeaders(array $caller, array $managed): array
    {
        $merged = [];
        foreach ($caller as $name => $value) {
            $merged[$name] = (array) $value;
        }
        foreach ($managed as $name => $value) {
            $merged[$name] = $value;
        }
        return $merged;
    }
}

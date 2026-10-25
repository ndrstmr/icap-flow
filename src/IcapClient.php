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
use Ndrstmr\Icap\Cache\OptionsCacheInterface;
use Ndrstmr\Icap\Cache\OptionsCacheKey;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
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
        private ?OptionsCacheInterface $optionsCache = null,
    ) {
        $this->previewStrategy = $previewStrategy ?? new DefaultPreviewStrategy(
            $config->getVirusFoundHeaders(),
        );
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
        return $this->run(
            OperationDeadline::begin($cancellation, $this->config->getOperationTimeout()),
            function (OperationDeadline $deadline) use ($request): ScanResult {
                $context = $this->logContext($request);
                $this->logger->info('ICAP request started', $context);

                $response = null;
                try {
                    $response = $this->executeRaw($request, $deadline)->await();
                    // An answer that comes in after the end of the operation is none (M10).
                    $deadline->checkpoint();
                    $result = $this->interpretResponse($response, $this->config);
                } catch (\Throwable $e) {
                    $e = $deadline->outcome($e);
                    $this->logFailure($context, $response, $e);
                    throw $e;
                }

                $this->logger->info('ICAP request completed', $context + [
                    'statusCode' => $response->statusCode,
                    'infected'   => $result->isInfected(),
                ]);

                return $result;
            },
        );
    }

    /**
     * Runs one public operation in a fiber of its own, inside the budget that began with its call (M10). What leaves
     * the operation is checked against the clock before it is returned: a result that came in late (a producer, a
     * transport or a cache that held the event loop) is a timeout, not a success. The deadline is closed in `finally`.
     *
     * @template T
     *
     * @param \Closure(OperationDeadline): T $operation
     *
     * @return Future<T>
     */
    private function run(OperationDeadline $deadline, \Closure $operation): Future
    {
        /** @var Future<T> $future */
        $future = \Amp\async(static function () use ($deadline, $operation): mixed {
            try {
                $result = $operation($deadline);
                $deadline->checkpoint();

                return $result;
            } catch (\Throwable $e) {
                throw $deadline->outcome($e);
            } finally {
                $deadline->close();
            }
        });

        return $future;
    }

    /**
     * The failed Future of an operation that was cancelled, or has run out of time, before a fiber of its own began
     * (an answer from the OPTIONS cache), or null if it is still wanted and in time. The deadline is closed with it.
     *
     * @phpstan-impure it reads the clock and the cancellation of the caller: the answer to a second call differs
     *
     * @return Future<never>|null
     */
    private static function stopped(OperationDeadline $deadline): ?Future
    {
        try {
            $deadline->checkpoint();
        } catch (\Throwable $e) {
            $deadline->close();

            return Future::error($e);
        }

        return null;
    }

    /**
     * Send the ICAP request and return the parsed {@see IcapResponse}
     * without the fail-secure status-code interpretation pass. Used
     * internally by the preview flow, where `100 Continue` is a
     * legitimate intermediate response.
     *
     * Visibility is intentionally `protected`: this method bypasses the
     * fail-secure status-code interpretation in {@see interpretResponse()},
     * so exposing it as part of the public surface would let callers
     * silently turn unexpected statuses (e.g. a stray `100` outside the
     * preview flow) into a `clean` verdict. External callers must use
     * {@see request()} or one of the `scanFile*()` methods instead. The class
     * is final: there is no outside access to this method.
     *
     * @return Future<IcapResponse>
     */
    protected function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null): Future
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
        $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());

        // Validate first so injection attempts never reach the cache key.
        $uri = $this->buildServiceUri($service);

        $cacheKey = $this->optionsCacheKey($service);

        $cache = $this->optionsCache;
        if ($cache !== null) {
            // The cache is foreign code and a hit is an answer like any other: a call that is cancelled or over does not
            // ask it, and gets no answer from it if it ended while the cache was asked. What ended it is the Future's.
            if (($stopped = self::stopped($deadline)) !== null) {
                return $stopped;
            }
            $cached = $cache->get($cacheKey);
            if (($stopped = self::stopped($deadline)) !== null) {
                return $stopped;
            }
            if ($cached !== null) {
                $this->assertOptionsStatus($cached->statusCode);
                $deadline->close();

                return Future::complete($cached);
            }
        }

        return $this->run($deadline, function (OperationDeadline $deadline) use ($uri, $cacheKey): IcapResponse {
            $request = new IcapRequest('OPTIONS', $uri);
            $context = $this->logContext($request);
            $this->logger->info('ICAP request started', $context);

            $response = null;
            try {
                $response = $this->executeRaw($request, $deadline)->await();
                $deadline->checkpoint();
                $this->assertOptionsStatus($response->statusCode);
            } catch (\Throwable $e) {
                $e = $deadline->outcome($e);
                $this->logFailure($context, $response, $e);
                throw $e;
            }

            $this->logger->info('ICAP request completed', $context + [
                'statusCode' => $response->statusCode,
            ]);

            // Cache the parsed IcapResponse, keyed by endpoint and service.
            // TTL is taken from the server's Options-TTL header
            // (RFC 3507 §4.10.2); fall back to 0 (no caching) when the
            // server didn't specify one.
            if ($this->optionsCache !== null) {
                // The logger is foreign code: a call that was cancelled, or ran out of time, while it logged the
                // answer does not begin a write to the cache.
                $deadline->checkpoint();
                $ttl = (int) (HeaderFields::single($response->headers, 'Options-TTL') ?? '0');
                $istag = HeaderFields::single($response->headers, 'ISTag');
                $this->optionsCache->set($cacheKey, $response, $ttl, $istag);
            }

            return $response;
        });
    }

    /**
     * Scan a local file via RESPMOD by wrapping it in a synthesized HTTP
     * response envelope (Content-Type: application/octet-stream,
     * Content-Length: <size of the open file>). The file contents are
     * streamed into the encapsulated body, so the full file never lives in
     * memory.
     *
     * The file is opened inside the returned Future, after a cancellation
     * that was already requested has been honoured, and closed again when
     * the operation ends, whatever the outcome (M08). The size comes from
     * the open handle; a handle without one is an error, never an empty
     * file (M07). The body goes out with exactly that many bytes: a file
     * that grew or shrank since is an error before the final chunk, never a
     * body of another size that looks complete. The caller's own streams
     * (see {@see request()}) are not touched.
     *
     * The Future fails with a \RuntimeException when the file cannot be
     * opened or sized, cannot be read to its end, or does not have the size
     * its handle reported.
     */
    #[\Override]
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());

        // Validate first so injection attempts never reach the socket.
        $this->validateIcapHeaders($extraHeaders);
        $uri = $this->buildServiceUri($service);

        return $this->run($deadline, function (OperationDeadline $deadline) use ($uri, $filePath, $extraHeaders): ScanResult {
            // A cancellation that was already requested, or an operation that is over, opens no file.
            $deadline->checkpoint();

            [$stream, $size] = $this->openSource($filePath);
            try {
                $httpResponse = new HttpResponse(
                    statusCode: 200,
                    headers: [
                        'Content-Type'   => ['application/octet-stream'],
                        'Content-Length' => [(string) $size],
                    ],
                    body: $stream,
                );

                $request = new IcapRequest(
                    method: 'RESPMOD',
                    uri: $uri,
                    // The full request goes out without a preview handshake, so a
                    // caller Preview would announce one that never happens; it stays
                    // out in any spelling. Allow, e.g. 204, is valid here.
                    headers: HeaderFields::without($extraHeaders, ['Preview']),
                    encapsulatedResponse: $httpResponse,
                );

                return $this->request($request, $deadline)->await();
            } finally {
                fclose($stream);
            }
        });
    }

    /**
     * Scan a local file via RESPMOD using a preview. The first
     * {@see $previewSize} bytes are sent along with the Preview /
     * Allow: 204 headers; if the file fits entirely within the preview
     * the request is terminated with `0; ieof\r\n\r\n` (RFC 3507 §4.5)
     * and no continuation round-trip is necessary.
     *
     * The preview size is 0 to 1 MiB (1048576 bytes) (B06); `0` sends the
     * headers alone. A size outside that range is refused with an
     * \InvalidArgumentException before the file or a socket is touched.
     * `ieof` is sent only after the end of the file was observed and its
     * length matches what the open handle reported (M07), and the rest of
     * the file, in the strict flow and in the full request of the two-request
     * flow, goes out with exactly that length as well. Like
     * {@see scanFile()}, the file is opened inside the returned Future and
     * closed when the operation ends.
     *
     * The Future fails with a \RuntimeException when the file cannot be
     * opened, sized or read, or has another length than its handle
     * reported.
     *
     * @throws \InvalidArgumentException When $previewSize is outside 0..1 MiB
     */
    #[\Override]
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());

        if ($previewSize !== null && ($previewSize < 0 || $previewSize > self::MAX_PREVIEW_SIZE)) {
            throw new \InvalidArgumentException(sprintf(
                'Preview size must be between 0 and %d bytes, got: %d',
                self::MAX_PREVIEW_SIZE,
                $previewSize,
            ));
        }
        $this->validateIcapHeaders($extraHeaders);

        $previewSize = $this->resolvePreviewSize($service, $previewSize);

        return $this->run($deadline, function (OperationDeadline $deadline) use ($service, $filePath, $previewSize, $extraHeaders): ScanResult {
            // A cancellation that was already requested, or an operation that is over, opens no file.
            $deadline->checkpoint();

            [$stream, $fileSize] = $this->openSource($filePath);

            try {
                if ($this->transport instanceof SessionAwareTransport) {
                    // Strict RFC 3507 §4.5 path — preview + continuation
                    // on the same socket, one logical ICAP request.
                    return $this->scanFileWithPreviewStrict(
                        $service,
                        $stream,
                        $fileSize,
                        $previewSize,
                        $extraHeaders,
                        $deadline,
                    );
                }
                // Legacy two-request approximation for non-session
                // transports (synchronous, custom impls).
                return $this->scanFileWithPreviewLegacy(
                    $service,
                    $stream,
                    $fileSize,
                    $previewSize,
                    $extraHeaders,
                    $deadline,
                );
            } finally {
                fclose($stream);
            }
        });
    }

    /**
     * Strict RFC 3507 §4.5 preview-continue: the preview and the
     * continuation travel on the same socket as one logical ICAP
     * request. Requires a {@see SessionAwareTransport}.
     *
     * @param resource                       $stream
     * @param array<string, string|string[]> $extraHeaders
     */
    private function scanFileWithPreviewStrict(
        string $service,
        mixed $stream,
        int $fileSize,
        int $previewSize,
        array $extraHeaders,
        OperationDeadline $deadline,
    ): ScanResult {
        \assert($this->transport instanceof SessionAwareTransport);

        [$previewBytes, $previewIsComplete] = $this->readPreview($stream, $fileSize, $previewSize);

        $previewEnvelope = new HttpResponse(
            statusCode: 200,
            headers: [
                'Content-Type'   => ['application/octet-stream'],
                'Content-Length' => [(string) $fileSize],
            ],
            body: $previewBytes,
        );
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

        $session = $this->transport->openSession($this->config, $deadline);
        // The socket is closed unless the exchange ended in a verdict that
        // passed every check below and no response asked for a close. An
        // exception, a status the flow does not allow or a 100 after `ieof`
        // leaves $reusable false, and the socket is never offered to the
        // pool, where the next user could see misaligned bytes.
        $reusable = false;
        try {
            $session->write($this->formatter->format($previewRequest));
            $previewIcapResponse = $this->parser->parse($session->readResponse());
            // Nothing is decided, and nothing pooled, on an answer that came in after the end of the operation.
            $deadline->checkpoint();
            $serverCloses = HeaderFields::hasToken($previewIcapResponse->headers, 'Connection', 'close');

            if ($previewIsComplete) {
                $result = $this->interpretResponse($previewIcapResponse, $this->config);
                $reusable = !$serverCloses;
                return $result;
            }

            $decision = $this->previewStrategy->handlePreviewResponse($previewIcapResponse);
            // The strategy is foreign code. A verdict that came in after the end of the operation, or after the caller
            // cancelled, is none: nothing is continued on this socket, and the socket is not given back (M10).
            $deadline->checkpoint();

            if ($decision !== PreviewDecision::CONTINUE_SENDING) {
                // Server's intermediate response is the verdict — nothing
                // to send on this socket.
                $result = new ScanResult(
                    isInfected: $decision === PreviewDecision::ABORT_INFECTED,
                    virusName: $decision === PreviewDecision::ABORT_INFECTED
                        ? $this->extractVirusName($previewIcapResponse, $this->config)
                        : null,
                    originalResponse: $previewIcapResponse,
                );
                $reusable = !$serverCloses;
                return $result;
            }

            // §4.5 continuation: ONLY the chunked body remainder, no
            // new ICAP head. The original RESPMOD envelope still wraps
            // the entire scan. Stream from the current position (past the
            // preview bytes) in CHUNK_SIZE blocks — never buffer the
            // entire remainder in memory (v2.1.2 OOM fix). The rest has the
            // length the envelope announced, or the exchange fails (M07).
            $session->write((new ChunkedBodyEncoder())->encodeRemainderFromStream(
                $stream,
                $fileSize - strlen($previewBytes),
            ));

            $finalIcapResponse = $this->parser->parse($session->readResponse());
            $deadline->checkpoint();
            $result = $this->interpretResponse($finalIcapResponse, $this->config);
            $reusable = !$serverCloses
                && !HeaderFields::hasToken($finalIcapResponse->headers, 'Connection', 'close');
            return $result;
        } finally {
            if ($reusable) {
                $session->release();
            } else {
                $session->close();
            }
        }
    }

    /**
     * Two-request approximation of RFC 3507 §4.5 used when the
     * transport doesn't expose a {@see SessionAwareTransport} surface
     * (synchronous transport, custom implementations). Works against
     * permissive servers but spends a second TCP/TLS handshake.
     *
     * @param resource                       $stream
     * @param array<string, string|string[]> $extraHeaders
     */
    private function scanFileWithPreviewLegacy(
        string $service,
        mixed $stream,
        int $fileSize,
        int $previewSize,
        array $extraHeaders,
        OperationDeadline $deadline,
    ): ScanResult {
        [$previewBytes, $previewIsComplete] = $this->readPreview($stream, $fileSize, $previewSize);

        $previewEnvelope = new HttpResponse(
            statusCode: 200,
            headers: [
                'Content-Type'   => ['application/octet-stream'],
                'Content-Length' => [(string) $fileSize],
            ],
            body: $previewBytes,
        );
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

        $previewIcapResponse = $this->executeRaw($previewRequest, $deadline)->await();
        $deadline->checkpoint();

        if ($previewIsComplete) {
            return $this->interpretResponse($previewIcapResponse, $this->config);
        }

        $decision = $this->previewStrategy->handlePreviewResponse($previewIcapResponse);
        // The strategy is foreign code: no second request is sent on the strength of a decision that came in after the
        // end of the operation, or after the caller cancelled.
        $deadline->checkpoint();
        if ($decision !== PreviewDecision::CONTINUE_SENDING) {
            return new ScanResult(
                isInfected: $decision === PreviewDecision::ABORT_INFECTED,
                virusName: $decision === PreviewDecision::ABORT_INFECTED
                    ? $this->extractVirusName($previewIcapResponse, $this->config)
                    : null,
                originalResponse: $previewIcapResponse,
            );
        }

        // The preview left the stream behind its first bytes; the full
        // request needs the file from its start. A stream that cannot go
        // back would send the rest of the file as if it were the whole.
        if (!rewind($stream)) {
            throw new \RuntimeException('Unable to rewind the file stream for the full request.');
        }
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
            // The full request is no preview: caller values for the preview
            // headers stay out of it as well, in any spelling.
            headers: HeaderFields::without($extraHeaders, ['Preview', 'Allow']),
            encapsulatedResponse: $fullResponse,
        );
        return $this->request($fullRequest, $deadline)->await();
    }

    private const int DEFAULT_PREVIEW_SIZE = 1024;

    /**
     * Largest preview the client announces and reads: 1 MiB. A preview is
     * read into memory in one piece, so a size taken from the caller or from
     * a cached OPTIONS response must never decide an allocation unchecked
     * (B06). A limit of this client, not of RFC 3507.
     */
    private const int MAX_PREVIEW_SIZE = 1_048_576;

    /**
     * Resolve the effective preview size. When the caller passes null,
     * the OPTIONS cache is consulted for the server's advertised
     * `Preview` header (RFC 3507 §4.10.2). Falls back to
     * {@see DEFAULT_PREVIEW_SIZE} when no cache is configured, the
     * cache has no entry, or the cached response lacks a `Preview`
     * header.
     *
     * @throws IcapMalformedResponseException When the cached response
     *         carries conflicting `Preview` fields, or a `Preview` value
     *         that is not a non-negative decimal integer
     */
    private function resolvePreviewSize(string $service, ?int $previewSize): int
    {
        if ($previewSize !== null) {
            return $previewSize;
        }

        if ($this->optionsCache !== null) {
            $cached = $this->optionsCache->get($this->optionsCacheKey($service));
            if ($cached !== null) {
                $advertised = HeaderFields::single($cached->headers, 'Preview');
                if ($advertised !== null) {
                    return $this->boundedPreviewSize($advertised);
                }
            }
        }

        return self::DEFAULT_PREVIEW_SIZE;
    }

    /**
     * A `Preview` value from a cached OPTIONS response is untrusted text. It
     * is checked as a non-negative decimal before any integer cast, so a
     * value that does not fit an int cannot wrap into something else; a
     * valid value above the limit is clamped to it, and `0` stays `0`.
     *
     * @throws IcapMalformedResponseException When the value is not a
     *         non-negative decimal integer
     */
    private function boundedPreviewSize(string $advertised): int
    {
        if (preg_match('/^[0-9]+\z/', $advertised) !== 1) {
            throw new IcapMalformedResponseException(
                'Cached OPTIONS response carries a Preview value that is not a non-negative decimal integer.',
            );
        }

        $digits = ltrim($advertised, '0');
        if (strlen($digits) > strlen((string) self::MAX_PREVIEW_SIZE)) {
            return self::MAX_PREVIEW_SIZE;
        }

        return min((int) $digits, self::MAX_PREVIEW_SIZE);
    }

    /**
     * Open a file for reading and take its size from the open handle. The
     * handle is what gets read: a second lookup of the path can describe
     * another file by then, and a size that cannot be established is an
     * error, not an empty file (M07).
     *
     * @return array{resource, int}
     *
     * @throws \RuntimeException When the file cannot be opened or sized
     */
    private function openSource(string $filePath): array
    {
        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open file: ' . $filePath);
        }

        // The caller's `finally` closes the handle only once it has it. Until
        // then any failure closes it here, a throwing lookup included: the
        // trace of that exception holds the handle for as long as the
        // exception is kept.
        try {
            $stat = fstat($stream);
            if ($stat === false || $stat['size'] < 0) {
                throw new \RuntimeException('Unable to determine the size of file: ' . $filePath);
            }

            return [$stream, $stat['size']];
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    /**
     * Read the preview from the start of the opened source and decide from
     * what the stream itself delivers whether it is the whole body. A
     * preview that is complete (`ieof`) needs the end of the stream to be
     * observed and to match the size the handle reported, not an end
     * inferred from a size measured earlier (M07). A failing or stalling
     * read is an error, not a shorter preview (B08).
     *
     * @param  resource $stream
     * @return array{string, bool} the preview bytes and whether they are the whole body
     *
     * @throws \RuntimeException When the source cannot be read, or ends
     *         at another length than its handle reported
     */
    private function readPreview(mixed $stream, int $fileSize, int $previewSize): array
    {
        $complete = $fileSize <= $previewSize;
        $wanted = $complete ? $fileSize : $previewSize;

        $bytes = '';
        while (strlen($bytes) < $wanted) {
            $block = $this->readSource($stream, max(1, $wanted - strlen($bytes)));
            if ($block === null) {
                throw new \RuntimeException(sprintf(
                    'The file ended after %d of the %d bytes its handle reported.',
                    strlen($bytes),
                    $fileSize,
                ));
            }
            $bytes .= $block;
        }

        if ($complete && $this->readSource($stream, 1) !== null) {
            throw new \RuntimeException(sprintf(
                'The file holds more than the %d bytes its handle reported.',
                $fileSize,
            ));
        }

        return [$bytes, $complete];
    }

    /**
     * One read from the source: the next bytes, or null at its confirmed end.
     *
     * @param  resource    $stream
     * @param  int<1, max> $length bytes to ask for
     *
     * @throws \RuntimeException When the read fails or makes no progress
     *         before the end
     */
    private function readSource(mixed $stream, int $length): ?string
    {
        $block = fread($stream, $length);
        if ($block === false || ($block === '' && !feof($stream))) {
            throw new \RuntimeException('Unable to read the file stream to its end.');
        }

        return $block === '' ? null : $block;
    }

    /**
     * Build the ICAP URI for $service on the configured server. Finding H
     * and N04: user-controlled service paths could otherwise sneak CR/LF
     * into the request line or move the URI authority. The check runs
     * before any wire byte has been written.
     */
    private function buildServiceUri(string $service): string
    {
        return IcapUri::forService($this->config->host, $this->config->port, $service);
    }

    /**
     * The key of the OPTIONS cache entry of a service. One place for
     * options() and the preview size lookup, so both name the same entry (M12).
     */
    private function optionsCacheKey(string $service): string
    {
        return OptionsCacheKey::forService($this->config->host, $this->config->port, $service);
    }

    /**
     * The only context the client logs (M16): the method as one of the three
     * ICAP methods, or `other`, and the configured port. A URI, a host, a
     * service or file path, header and body values and exception messages
     * carry what callers and servers put in them, which a log must not keep.
     *
     * @return array{method: string, port: int}
     */
    private function logContext(IcapRequest $request): array
    {
        return [
            'method' => in_array($request->method, ['OPTIONS', 'REQMOD', 'RESPMOD'], true) ? $request->method : 'other',
            'port'   => $this->config->port,
        ];
    }

    /**
     * The failure event: the status code of the response if one came in, and
     * the class of the exception, without a message, a trace or the exception
     * itself. The class of an anonymous class names the file it was written in
     * after a NUL byte; that part stays out as well.
     *
     * @param array{method: string, port: int} $context
     */
    private function logFailure(array $context, ?IcapResponse $response, \Throwable $e): void
    {
        $this->logger->warning('ICAP request failed', $context + [
            'statusCode'      => $response?->statusCode,
            'exception_class' => strstr($e::class, "\0", true) ?: $e::class,
        ]);
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

        $this->assertSuccessfulStatus($code);

        // Fail-secure backstop: any status that is neither a clean-scan
        // signal (204/200/206) nor a recognised failure (100/4xx/5xx) —
        // e.g. 1xx other than 100, 3xx, 6xx+ — is a protocol violation.
        throw new IcapProtocolException(
            'Unexpected ICAP status: ' . $code,
            $code,
        );
    }

    /**
     * The status of an OPTIONS answer, fresh or from the cache: only a 200
     * (RFC 3507 §4.10.2) describes the service. 100, 4xx and 5xx keep their
     * types from {@see assertSuccessfulStatus()}; every other status is a
     * protocol error: a 204 or 206 belongs to the adaptation of a message, and
     * a 1xx, 3xx or 6xx is no answer to OPTIONS at all. What is rejected here
     * is neither returned nor cached. Scans keep their own rules in
     * {@see interpretResponse()}.
     *
     * @throws IcapProtocolException When the status is not 200 and not a 4xx or 5xx
     * @throws IcapClientException   When the status is in the 4xx range
     * @throws IcapServerException   When the status is in the 5xx range
     */
    private function assertOptionsStatus(int $code): void
    {
        $this->assertSuccessfulStatus($code);

        if ($code !== 200) {
            throw new IcapProtocolException('Unexpected ICAP status for OPTIONS: ' . $code, $code);
        }
    }

    /**
     * Apply the fail-secure verdict for status codes that do not signal a
     * successful exchange. Used by {@see interpretResponse()} for scans and
     * by {@see assertOptionsStatus()} for OPTIONS, which adds its own rule on
     * top.
     *
     * @throws IcapProtocolException When the response is `100 Continue`
     *         outside the preview flow
     * @throws IcapClientException   When the status is in the 4xx range
     * @throws IcapServerException   When the status is in the 5xx range
     */
    private function assertSuccessfulStatus(int $code): void
    {
        if ($code === 100) {
            throw new IcapProtocolException(
                'ICAP 100 Continue is only valid during a preview exchange; received outside preview flow.',
                $code,
            );
        }

        $failure = IcapStatus::failure($code);
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function extractVirusName(IcapResponse $response, Config $config): ?string
    {
        return HeaderFields::first($response->headers, $config->getVirusFoundHeaders());
    }

    /**
     * Reject header names or values that would break the wire format
     * (Finding H, B04): names must be tokens, values strings without
     * control characters. The formatter applies the same check; running
     * it here as well makes the scan helpers fail before they open the
     * file or start a request.
     *
     * @param array<string, string|string[]> $headers
     */
    private function validateIcapHeaders(array $headers): void
    {
        HeaderFields::validated($headers, 'ICAP');
    }

    /**
     * Merge caller-supplied headers with library-managed headers. The
     * library-managed map always wins, whatever the spelling of the
     * caller's names, so protocol-critical headers (Preview, Allow) stay
     * out of caller control and go out once. Host and Encapsulated are
     * the formatter's.
     *
     * @param  array<string, string|string[]> $caller
     * @param  array<string, string[]>        $managed
     * @return array<string, string[]>
     */
    private function mergeHeaders(array $caller, array $managed): array
    {
        $merged = [];
        foreach (HeaderFields::without($caller, array_keys($managed)) as $name => $value) {
            $merged[$name] = (array) $value;
        }
        foreach ($managed as $name => $value) {
            $merged[$name] = $value;
        }
        return $merged;
    }
}

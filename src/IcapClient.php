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
use Ndrstmr\Icap\Cache\OptionsEntry;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapClientException;
use Ndrstmr\Icap\Exception\IcapProtocolException;
use Ndrstmr\Icap\Exception\IcapServerException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\DiscardingTransport;
use Ndrstmr\Icap\Transport\DiscardingTransportSession;
use Ndrstmr\Icap\Transport\SessionAwareTransport;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\TransportSession;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core asynchronous ICAP client used by the synchronous wrapper.
 *
 * With a {@see CicapVerdict} the client runs in the verified mode for the c-icap `virus_scan` service with ClamAV: every
 * answer to a scan, to a preview and to the rest of the file, whichever helper asked for it, is read by that verdict, and
 * the client scans with the service of the verdict only. Without one, the default rules apply (a 204, and a 200 or 206
 * without a header of the {@see Config} list, are clean).
 */
final class IcapClient implements IcapClientInterface, DiscardingIcapClient
{
    private PreviewStrategyInterface $previewStrategy;
    private LoggerInterface $logger;

    /**
     * @param CicapVerdict|null $verdict Turns the verified mode on, for the service the verdict is made for. It decides
     *                                   every answer, so a preview strategy of your own, which could decide before it,
     *                                   is refused.
     *
     * @throws \InvalidArgumentException When a verdict is combined with a preview strategy other than the default one
     */
    public function __construct(
        private Config $config,
        private TransportInterface $transport,
        private RequestFormatterInterface $formatter,
        private ResponseParserInterface $parser,
        ?PreviewStrategyInterface $previewStrategy = null,
        ?LoggerInterface $logger = null,
        private ?OptionsCacheInterface $optionsCache = null,
        private readonly ?CicapVerdict $verdict = null,
    ) {
        if ($verdict !== null && $previewStrategy !== null && !$previewStrategy instanceof DefaultPreviewStrategy) {
            throw new \InvalidArgumentException(
                $previewStrategy::class . ' would decide on the answer to a preview before the verified mode does; use one of the two.',
            );
        }
        $this->previewStrategy = $previewStrategy ?? new DefaultPreviewStrategy(
            $config->getVirusFoundHeaders(),
        );
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Convenience factory for synchronous environments.
     */
    public static function forServer(string $host, int $port = 1344, ?CicapVerdict $verdict = null): self
    {
        $config = new Config($host, $port);
        return new self(
            $config,
            new SynchronousStreamTransport(),
            new RequestFormatter(),
            self::parserFor($config),
            verdict: $verdict,
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

    /**
     * @throws \InvalidArgumentException In the verified mode, when the request is for another service than the one of the
     *                                   verdict
     */
    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        if ($this->verdict !== null) {
            $this->assertVerifiedService(IcapUri::serviceOf($request->uri));
        }

        return $this->requestWith($request, $cancellation, null);
    }

    /**
     * One request and the verdict on its response; with a budget, the body of the response is received and dropped.
     *
     * @return Future<ScanResult>
     */
    private function requestWith(IcapRequest $request, ?Cancellation $cancellation, ?int $discardBudget): Future
    {
        return $this->run(
            OperationDeadline::begin($cancellation, $this->config->getOperationTimeout()),
            function (OperationDeadline $deadline) use ($request, $discardBudget): ScanResult {
                $context = $this->logContext($request);
                $this->logger->info('ICAP request started', $context);

                $response = null;
                try {
                    $response = $this->executeRaw($request, $deadline, $discardBudget)->await();
                    // An answer that comes in after the end of the operation is none (M10).
                    $deadline->checkpoint();
                    // The service had the whole message unless the request ended in the start of it.
                    $result = $this->interpretResponse($response, $this->config, complete: !$request->isPartialPreview());
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
     * With a `$discardBudget` the response is received and checked by the transport without being kept
     * (see {@see DiscardingIcapClient}), and the parser is not asked.
     *
     * @return Future<IcapResponse>
     */
    protected function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null, ?int $discardBudget = null): Future
    {
        /** @var Future<IcapResponse> $future */
        $future = \Amp\async(function () use ($request, $cancellation, $discardBudget): IcapResponse {
            $chunks = $this->formatter->format($request);
            if ($discardBudget !== null) {
                return $this->discardingTransport()->requestDiscardingBody($this->config, $chunks, $discardBudget, $cancellation)->await();
            }
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

        return $this->run($deadline, function (OperationDeadline $deadline) use ($uri, $cacheKey): IcapResponse {
            $cached = $this->cachedOptions($cacheKey, $deadline);
            if ($cached !== null) {
                return $cached;
            }

            $request = new IcapRequest('OPTIONS', $uri);
            $context = $this->logContext($request);
            $this->logger->info('ICAP request started', $context);

            $response = null;
            try {
                $response = $this->executeRaw($request, $deadline)->await();
                $received = hrtime(true);
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

            // Cache the answer under the key of its endpoint, service and security profile for the server's
            // Options-TTL (RFC 3507 §4.10.2) at most an hour. The time counts from the moment the answer came in:
            // foreign code (the logger) may have held it since, and an answer whose time is over is not written.
            if ($this->optionsCache !== null) {
                // The logger is foreign code: a call that was cancelled, or ran out of time, while it logged the
                // answer does not begin a write to the cache.
                $deadline->checkpoint();
                $istag = HeaderFields::single($response->headers, 'ISTag');
                $ttl = OptionsEntry::ttl(HeaderFields::single($response->headers, 'Options-TTL')) - intdiv(hrtime(true) - $received, 1_000_000_000);
                if ($ttl > 0 && OptionsEntry::keeps($response)) {
                    $this->optionsCache->set($cacheKey, $response, $ttl, $istag);
                }
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
     * The file is opened once, inside the returned Future, after a
     * cancellation that was already requested has been honoured, read
     * through amphp/file (see {@see LocalFile}) and closed when the
     * operation ends. Its size is that of the open file, and the body goes
     * out with exactly that many bytes: a file that grew or shrank since is
     * an error before the final chunk. The caller's own streams (see
     * {@see request()}) are not touched.
     *
     * The Future fails with an {@see IcapStorageException} when the file
     * cannot be opened, sized, read to its end or closed, does not have the
     * size it had when it was opened, or the request did not read it to its
     * end (a formatter or transport of your own that took less).
     */
    #[\Override]
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        return $this->scanFileWith($service, $filePath, $extraHeaders, $cancellation, null);
    }

    /**
     * {@see scanFile()} with the body of the response received and dropped, and only its size kept
     * ({@see IcapResponse::$discardedBodyBytes}); for a server that echoes a large file back. See
     * {@see DiscardingIcapClient}.
     *
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     *
     * @throws \InvalidArgumentException When the budget is below 1
     * @throws \LogicException           When the transport or the parser cannot discard
     */
    #[\Override]
    public function scanFileDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $this->assertCanDiscard($maxResponseBytes);

        return $this->scanFileWith($service, $filePath, $extraHeaders, $cancellation, $maxResponseBytes);
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     */
    private function scanFileWith(
        string $service,
        string $filePath,
        array $extraHeaders,
        ?Cancellation $cancellation,
        ?int $discardBudget,
    ): Future {
        $this->assertVerifiedService($service);
        $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());

        // Validate first so injection attempts never reach the socket.
        $this->validateIcapHeaders($extraHeaders);
        $uri = $this->buildServiceUri($service);

        return $this->run($deadline, function (OperationDeadline $deadline) use ($uri, $filePath, $extraHeaders, $discardBudget): ScanResult {
            // A cancellation that was already requested, or an operation that is over, opens no file.
            $deadline->checkpoint();

            $file = LocalFile::open($filePath, $deadline);
            try {
                $httpResponse = new HttpResponse(
                    statusCode: 200,
                    headers: [
                        'Content-Type'   => ['application/octet-stream'],
                        'Content-Length' => [(string) $file->size],
                    ],
                    body: $file,
                );

                $request = new IcapRequest(
                    method: 'RESPMOD',
                    uri: $uri,
                    // The full request goes out without a preview handshake, so a
                    // caller Preview would announce one that never happens; it stays
                    // out in any spelling. Allow, e.g. 204, is valid here.
                    headers: $this->wholeFileHeaders($extraHeaders, ['Preview']),
                    encapsulatedResponse: $httpResponse,
                );

                $result = $this->requestWith($request, $deadline, $discardBudget)->await();
                // A formatter or transport that took less than the file leaves the verdict to another body.
                $file->assertRead();
                $file->close();

                return $result;
            } finally {
                $file->discard();
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
     * The preview size is 0 to 1 MiB (1048576 bytes); `0` sends the
     * headers alone. A size outside that range is refused with an
     * \InvalidArgumentException before the file or a socket is touched.
     * `ieof` is sent only after the end of the file was observed and its
     * length matches the size of the open file, and the rest of
     * the file, in the strict flow and in the full request of the two-request
     * flow, goes out with exactly that length as well. Like
     * {@see scanFile()}, the file is opened once inside the returned Future
     * and closed when the operation ends.
     *
     * The Future fails with an {@see IcapStorageException} when the file
     * cannot be opened, sized, read or closed, or has another length than it
     * had when it was opened.
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
        return $this->scanFileWithPreviewWith($service, $filePath, $previewSize, $extraHeaders, $cancellation, null);
    }

    /**
     * {@see scanFileWithPreview()} with the body of every response of the exchange received and dropped, as
     * {@see scanFileDiscardingBody()} does. The budget holds for each response on its own.
     *
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     *
     * @throws \InvalidArgumentException When the budget is below 1, or $previewSize is outside 0..1 MiB
     * @throws \LogicException           When the transport or the parser cannot discard, or the client has a
     *                                   preview strategy other than {@see DefaultPreviewStrategy}
     */
    #[\Override]
    public function scanFileWithPreviewDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $this->assertCanDiscard($maxResponseBytes);
        // A strategy of its own may read the body of the answer to the preview, which is not there.
        if (!$this->previewStrategy instanceof DefaultPreviewStrategy) {
            throw new \LogicException($this->previewStrategy::class . ' may judge the body of the answer to the preview, which this mode does not keep; only ' . DefaultPreviewStrategy::class . ' is used with it.');
        }

        return $this->scanFileWithPreviewWith($service, $filePath, $previewSize, $extraHeaders, $cancellation, $maxResponseBytes);
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     */
    private function scanFileWithPreviewWith(
        string $service,
        string $filePath,
        ?int $previewSize,
        array $extraHeaders,
        ?Cancellation $cancellation,
        ?int $discardBudget,
    ): Future {
        $this->assertVerifiedService($service);
        $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());

        if ($previewSize !== null && ($previewSize < 0 || $previewSize > self::MAX_PREVIEW_SIZE)) {
            throw new \InvalidArgumentException(sprintf(
                'Preview size must be between 0 and %d bytes, got: %d',
                self::MAX_PREVIEW_SIZE,
                $previewSize,
            ));
        }
        $this->validateIcapHeaders($extraHeaders);

        return $this->run($deadline, function (OperationDeadline $deadline) use ($service, $filePath, $previewSize, $extraHeaders, $discardBudget): ScanResult {
            $previewSize = $this->resolvePreviewSize($service, $previewSize, $deadline);
            // A cancellation that was already requested, or an operation that is over, opens no file.
            $deadline->checkpoint();

            $file = LocalFile::open($filePath, $deadline);

            try {
                $result = $this->transport instanceof SessionAwareTransport
                    // Strict RFC 3507 §4.5 path — preview + continuation
                    // on the same socket, one logical ICAP request.
                    ? $this->scanFileWithPreviewStrict($service, $file, $previewSize, $extraHeaders, $deadline, $discardBudget)
                    // Legacy two-request approximation for non-session
                    // transports (synchronous, custom impls).
                    : $this->scanFileWithPreviewLegacy($service, $file, $previewSize, $extraHeaders, $deadline, $discardBudget);
                $file->close();

                return $result;
            } finally {
                $file->discard();
            }
        });
    }

    /**
     * Strict RFC 3507 §4.5 preview-continue: the preview and the
     * continuation travel on the same socket as one logical ICAP
     * request. Requires a {@see SessionAwareTransport}.
     *
     * @param array<string, string|string[]> $extraHeaders
     */
    private function scanFileWithPreviewStrict(
        string $service,
        LocalFile $file,
        int $previewSize,
        array $extraHeaders,
        OperationDeadline $deadline,
        ?int $discardBudget,
    ): ScanResult {
        \assert($this->transport instanceof SessionAwareTransport);

        $fileSize = $file->size;
        [$previewBytes, $previewIsComplete] = $this->readPreview($file, $previewSize);

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
            // A session that cannot discard is found out before a byte is sent on it.
            $readResponse = $this->responseReader($session, $discardBudget);
            $session->write($this->formatter->format($previewRequest));
            $previewIcapResponse = $readResponse();
            // Nothing is decided, and nothing pooled, on an answer that came in after the end of the operation.
            $deadline->checkpoint();
            $serverCloses = HeaderFields::hasToken($previewIcapResponse->headers, 'Connection', 'close');

            if ($previewIsComplete) {
                $result = $this->interpretResponse($previewIcapResponse, $this->config);
                $reusable = !$serverCloses;
                return $result;
            }

            $result = $this->previewResult($previewIcapResponse, $deadline);
            if ($result !== null) {
                // Server's intermediate response is the verdict — nothing
                // to send on this socket.
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
                $file,
                $fileSize - strlen($previewBytes),
            ));

            $finalIcapResponse = $readResponse();
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
     * @param array<string, string|string[]> $extraHeaders
     */
    private function scanFileWithPreviewLegacy(
        string $service,
        LocalFile $file,
        int $previewSize,
        array $extraHeaders,
        OperationDeadline $deadline,
        ?int $discardBudget,
    ): ScanResult {
        $fileSize = $file->size;
        [$previewBytes, $previewIsComplete] = $this->readPreview($file, $previewSize);

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

        $previewIcapResponse = $this->executeRaw($previewRequest, $deadline, $discardBudget)->await();
        $deadline->checkpoint();

        if ($previewIsComplete) {
            return $this->interpretResponse($previewIcapResponse, $this->config);
        }

        $result = $this->previewResult($previewIcapResponse, $deadline);
        if ($result !== null) {
            return $result;
        }

        // The preview left the file behind its first bytes; the full
        // request needs it from its start. A file that cannot go back
        // would send the rest of it as if it were the whole.
        $file->rewind();
        $fullResponse = new HttpResponse(
            statusCode: 200,
            headers: [
                'Content-Type'   => ['application/octet-stream'],
                'Content-Length' => [(string) $fileSize],
            ],
            body: $file,
        );
        $fullRequest = new IcapRequest(
            method: 'RESPMOD',
            uri: $this->buildServiceUri($service),
            // The full request is no preview: caller values for the preview
            // headers stay out of it as well, in any spelling.
            headers: $this->wholeFileHeaders($extraHeaders, ['Preview', 'Allow']),
            encapsulatedResponse: $fullResponse,
        );
        $result = $this->requestWith($fullRequest, $deadline, $discardBudget)->await();
        // As in scanFile(): the verdict counts only if the request took the whole file.
        $file->assertRead();

        return $result;
    }

    /**
     * What the answer to a preview that holds only the start of the file decides: the verdict if the answer is one,
     * null if the service wants the rest.
     *
     * @throws IcapProtocolException When the verified mode finds that the service did not scan the file
     */
    private function previewResult(IcapResponse $answer, OperationDeadline $deadline): ?ScanResult
    {
        $failure = IcapStatus::failure($answer->statusCode);
        if ($failure !== null) {
            throw $failure;
        }
        if (!in_array($answer->statusCode, [100, 200, 204, 206], true)) {
            throw new IcapProtocolException('Unexpected preview status code: ' . $answer->statusCode, $answer->statusCode);
        }

        if ($this->verdict !== null) {
            // Only a 100 asks for the rest, and only one that reports no infection does. A 204 now is a service that does
            // not scan this file. Either way the exception ends the exchange: no continuation, no reuse of the socket.
            if ($answer->statusCode === 100) {
                $this->verdict->assertContinuation($answer);

                return null;
            }

            return $this->interpretResponse($answer, $this->config, complete: false);
        }

        $decision = $this->previewStrategy->handlePreviewResponse($answer);
        // The strategy is foreign code. A verdict that came in after the end of the operation, or after the caller
        // cancelled, is none: nothing is continued, and the socket is not given back (M10).
        $deadline->checkpoint();

        if (($answer->statusCode === 100) !== ($decision === PreviewDecision::CONTINUE_SENDING)) {
            throw new IcapProtocolException('Preview decision does not match ICAP status: ' . $answer->statusCode, $answer->statusCode);
        }

        return $decision === PreviewDecision::CONTINUE_SENDING ? null : new ScanResult(
            isInfected: $decision === PreviewDecision::ABORT_INFECTED,
            virusName: $decision === PreviewDecision::ABORT_INFECTED
                ? $this->extractVirusName($answer, $this->config)
                : null,
            originalResponse: $answer,
        );
    }

    /**
     * The verified mode holds for the service of its verdict and for no other: the answers of another one, say a service
     * that skips a type, look like those of a scan. Checked at the call, before a file or a connection is touched.
     *
     * @throws \InvalidArgumentException When the mode is on and the service is not the one of the verdict
     */
    private function assertVerifiedService(string $service): void
    {
        if ($this->verdict !== null && $service !== $this->verdict->service) {
            throw new \InvalidArgumentException(sprintf(
                'The verified mode is made for the service %s; it does not scan with another one.',
                $this->verdict->service,
            ));
        }
    }

    /**
     * The headers of a request that carries the whole file. The ones named in `$managed` are not the caller's. In the
     * verified mode the request offers 204: only a 204 shows that the service scanned the file, a clean file would
     * otherwise come back as a message that looks like one it did not scan.
     *
     * @param array<string, string|string[]> $extraHeaders
     * @param list<string>                   $managed
     *
     * @return array<string, string|string[]>
     */
    private function wholeFileHeaders(array $extraHeaders, array $managed): array
    {
        $headers = HeaderFields::without($extraHeaders, $managed);

        return $this->verdict === null ? $headers : $this->mergeHeaders($headers, ['Allow' => ['204']]);
    }

    /**
     * The next response of a session, parsed from its string, or received and dropped by the session if there is a
     * budget.
     *
     * @return \Closure(): IcapResponse
     *
     * @throws \LogicException When there is a budget and the session cannot discard
     */
    private function responseReader(TransportSession $session, ?int $discardBudget): \Closure
    {
        if ($discardBudget === null) {
            return fn (): IcapResponse => $this->parser->parse($session->readResponse());
        }
        if (!$session instanceof DiscardingTransportSession) {
            throw new \LogicException(sprintf('The session of %s cannot receive a response without keeping it.', $this->transport::class));
        }

        return static fn (): IcapResponse => $session->readResponseDiscardingBody($discardBudget);
    }

    /**
     * The discarding mode needs a transport that can receive a response without keeping it, and the parser of this
     * library: the response is read by the decoder, and another parser would be passed by without a word.
     *
     * @throws \InvalidArgumentException When the budget is below 1
     * @throws \LogicException           When the transport or the parser cannot discard
     */
    private function assertCanDiscard(int $maxResponseBytes): void
    {
        if ($maxResponseBytes < 1) {
            throw new \InvalidArgumentException('maxResponseBytes must be >= 1, got: ' . $maxResponseBytes);
        }
        $this->discardingTransport();
        if (!$this->parser instanceof ResponseParser) {
            throw new \LogicException('The discarding mode reads the response with the ResponseParser of this library; ' . $this->parser::class . ' would be passed by.');
        }
    }

    private function discardingTransport(): DiscardingTransport
    {
        return $this->transport instanceof DiscardingTransport
            ? $this->transport
            : throw new \LogicException($this->transport::class . ' cannot receive a response without keeping it; use a transport that implements ' . DiscardingTransport::class . '.');
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
     * cache has no usable entry (see {@see cachedOptions()}), or the cached
     * response lacks a `Preview` header.
     */
    private function resolvePreviewSize(string $service, ?int $previewSize, OperationDeadline $deadline): int
    {
        if ($previewSize !== null) {
            return $previewSize;
        }

        $cached = $this->cachedOptions($this->optionsCacheKey($service), $deadline);
        $advertised = $cached === null ? null : HeaderFields::single($cached->headers, 'Preview');

        return $advertised === null ? self::DEFAULT_PREVIEW_SIZE : $this->boundedPreviewSize($advertised);
    }

    /**
     * The cached OPTIONS answer, or null. A call that is cancelled or over does not ask the cache, and one that ends
     * while the cache is asked gets no answer from it. Whatever the cache is, an answer that fails the check of
     * {@see OptionsEntry::keeps()} (status, budgets, fields), or that the limits of this client would have refused from
     * the server ({@see ResponseDecoder::admits()}), is a miss: the client does not use it.
     */
    private function cachedOptions(string $key, OperationDeadline $deadline): ?IcapResponse
    {
        if ($this->optionsCache === null) {
            return null;
        }

        $deadline->checkpoint();
        $cached = $this->optionsCache->get($key);
        $deadline->checkpoint();

        return $cached !== null
            && OptionsEntry::keeps($cached)
            && ResponseDecoder::admits(
                $cached,
                $this->config->getMaxResponseSize(),
                $this->config->getMaxHeaderCount(),
                $this->config->getMaxHeaderLineLength(),
            )
            ? $cached
            : null;
    }

    /**
     * A `Preview` value from a cached OPTIONS response, which {@see OptionsEntry::keeps()} has shown to be a
     * non-negative decimal. The digits are measured before any integer cast, so a value that does not fit an int cannot
     * wrap into something else; a value above the limit is clamped to it, and `0` stays `0`.
     */
    private function boundedPreviewSize(string $advertised): int
    {
        $digits = ltrim($advertised, '0');
        if (strlen($digits) > strlen((string) self::MAX_PREVIEW_SIZE)) {
            return self::MAX_PREVIEW_SIZE;
        }

        return min((int) $digits, self::MAX_PREVIEW_SIZE);
    }

    /**
     * Read the preview from the start of the opened file and decide from
     * what the file itself delivers whether it is the whole body. A preview
     * that is complete (`ieof`) needs the end of the file to be observed and
     * to match the size it was measured at, not an end inferred from a size
     * taken earlier. A failing or stalling read is an error, not a shorter
     * preview.
     *
     * @return array{string, bool} the preview bytes and whether they are the whole body
     *
     * @throws IcapStorageException When the file cannot be read, or ends at
     *         another length than it was measured at
     */
    private function readPreview(LocalFile $file, int $previewSize): array
    {
        $fileSize = $file->size;
        $complete = $fileSize <= $previewSize;
        $wanted = $complete ? $fileSize : $previewSize;

        $bytes = '';
        while (strlen($bytes) < $wanted) {
            $block = $file->read(max(1, $wanted - strlen($bytes)));
            if ($block === null) {
                throw new IcapStorageException(sprintf(
                    'The file ended after %d of the %d bytes it was opened with.',
                    strlen($bytes),
                    $fileSize,
                ));
            }
            $bytes .= $block;
        }

        if ($complete && $file->read(1) !== null) {
            throw new IcapStorageException(sprintf(
                'The file holds more than the %d bytes it was opened with.',
                $fileSize,
            ));
        }

        return [$bytes, $complete];
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
     * options() and the preview size lookup, so both name the same entry,
     * of this client's security profile.
     */
    private function optionsCacheKey(string $service): string
    {
        return OptionsCacheKey::forService($this->config->host, $this->config->port, $service, $this->config->getTlsContext());
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
     *
     * In the verified mode the rules of the {@see CicapVerdict} apply to what is
     * left after that; `$complete` says whether the service had the whole file when
     * it answered.
     */
    private function interpretResponse(IcapResponse $response, Config $config, bool $complete = true): ScanResult
    {
        $code = $response->statusCode;

        if ($this->verdict !== null) {
            // 100 outside a preview, 4xx and 5xx are what they are in every mode; the rest is the verdict's.
            $this->assertSuccessfulStatus($code);

            return $this->verdict->interpret($response, $complete);
        }

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
     * The status of an OPTIONS answer from the server: only a 200
     * (RFC 3507 §4.10.2) describes the service. 100, 4xx and 5xx keep their
     * types from {@see assertSuccessfulStatus()}; every other status is a
     * protocol error: a 204 or 206 belongs to the adaptation of a message, and
     * a 1xx, 3xx or 6xx is no answer to OPTIONS at all. What is rejected here
     * is neither returned nor cached; a cached answer with another status is a
     * miss ({@see cachedOptions()}). Scans keep their own rules in
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

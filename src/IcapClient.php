<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapResponseException;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;

/**
 * Core asynchronous ICAP client used by the synchronous wrapper.
 */
final class IcapClient implements IcapClientInterface
{
    private PreviewStrategyInterface $previewStrategy;

    public function __construct(
        private Config $config,
        private TransportInterface $transport,
        private RequestFormatterInterface $formatter,
        private ResponseParserInterface $parser,
        ?PreviewStrategyInterface $previewStrategy = null,
    ) {
        $this->previewStrategy = $previewStrategy ?? new DefaultPreviewStrategy();
    }

    /**
     * Convenience factory for synchronous environments.
     */
    public static function forServer(string $host, int $port = 1344): self
    {
        return new self(
            new Config($host, $port),
            new SynchronousStreamTransport(),
            new RequestFormatter(),
            new ResponseParser(),
        );
    }

    /**
     * Factory using the default async transport.
     */
    public static function create(): self
    {
        return new self(
            new Config('127.0.0.1'),
            new AsyncAmpTransport(),
            new RequestFormatter(),
            new ResponseParser(),
            new DefaultPreviewStrategy(),
        );
    }

    #[\Override]
    public function request(IcapRequest $request): Future
    {
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($request): ScanResult {
            $chunks = $this->formatter->format($request);
            $responseString = $this->transport->request($this->config, $chunks)->await();

            $response = $this->parser->parse($responseString);

            return $this->interpretResponse($response, $this->config);
        });

        return $future;
    }

    #[\Override]
    public function options(string $service): Future
    {
        $uri = $this->buildServiceUri($service);
        $request = new IcapRequest('OPTIONS', $uri);
        return $this->request($request);
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
    public function scanFile(string $service, string $filePath): Future
    {
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
            uri: $this->buildServiceUri($service),
            encapsulatedResponse: $httpResponse,
        );

        return $this->request($request);
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
    public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): Future
    {
        if ($previewSize < 1) {
            throw new \InvalidArgumentException('Preview size must be >= 1, got: ' . $previewSize);
        }

        /** @var int<1, max> $previewSize */
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($service, $filePath, $previewSize): ScanResult {
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

            $previewResponse = new HttpResponse(
                statusCode: 200,
                headers: [
                    'Content-Type'   => ['application/octet-stream'],
                    'Content-Length' => [(string) $fileSize],
                ],
                body: $previewBytes,
            );

            $previewRequest = new IcapRequest(
                method: 'RESPMOD',
                uri: $this->buildServiceUri($service),
                headers: [
                    'Preview' => [(string) $previewSize],
                    'Allow'   => ['204'],
                ],
                encapsulatedResponse: $previewResponse,
                previewIsComplete: $previewIsComplete,
            );

            $previewResult = $this->request($previewRequest)->await();

            if ($previewIsComplete) {
                fclose($stream);
                return $previewResult;
            }

            $decision = $this->previewStrategy->handlePreviewResponse(
                $previewResult->getOriginalResponse(),
            );

            if ($decision !== PreviewDecision::CONTINUE_SENDING) {
                fclose($stream);
                return $previewResult;
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
                encapsulatedResponse: $fullResponse,
            );

            try {
                return $this->request($fullRequest)->await();
            } finally {
                fclose($stream);
            }
        });

        return $future;
    }

    private function buildServiceUri(string $service): string
    {
        $host = $this->config->host;
        if ($this->config->port !== 1344) {
            $host .= ':' . $this->config->port;
        }
        return sprintf('icap://%s%s', $host, $service);
    }

    private function interpretResponse(IcapResponse $response, Config $config): ScanResult
    {
        if ($response->statusCode === 204) {
            return new ScanResult(false, null, $response);
        }

        if ($response->statusCode === 200) {
            $header = $config->getVirusFoundHeader();
            $virus = $response->headers[$header][0] ?? null;

            if ($virus !== null) {
                return new ScanResult(true, $virus, $response);
            }

            return new ScanResult(false, null, $response);
        }

        if ($response->statusCode === 100) {
            return new ScanResult(false, null, $response);
        }

        throw new IcapResponseException('Unexpected ICAP status: ' . $response->statusCode, $response->statusCode);
    }
}

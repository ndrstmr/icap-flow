<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapResponseException;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\DefaultPreviewStrategy;

/**
 * Core asynchronous ICAP client used by the synchronous wrapper.
 */
class IcapClient
{
    /**
     * @param Config                       $config          Connection configuration
     * @param TransportInterface            $transport       Transport implementation
     * @param RequestFormatterInterface     $formatter       Formats outgoing requests
     * @param ResponseParserInterface       $parser          Parses incoming responses
     * @param PreviewStrategyInterface|null $previewStrategy Strategy for preview handling
     */
    public function __construct(
        private Config $config,
        private TransportInterface $transport,
        private RequestFormatterInterface $formatter,
        private ResponseParserInterface $parser,
        ?PreviewStrategyInterface $previewStrategy = null
    ) {
        $this->previewStrategy = $previewStrategy ?? new DefaultPreviewStrategy();
    }

    private PreviewStrategyInterface $previewStrategy;

    /**
     * Convenience factory for synchronous environments.
     */
    public static function forServer(string $host, int $port = 1344): self
    {
        return new self(new Config($host, $port), new SynchronousStreamTransport(), new RequestFormatter(), new ResponseParser());
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

    /**
     * Send a raw ICAP request.
     *
     * @param IcapRequest $request
     * @return Future<ScanResult>
     */
    public function request(IcapRequest $request): Future
    {
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($request): ScanResult {
            $raw = $this->formatter->format($request);
            $responseString = $this->transport->request($this->config, $raw)->await();

            $response = $this->parser->parse($responseString);

            return $this->interpretResponse($response, $this->config);
        });

        return $future;
    }

    /**
     * Issue an OPTIONS request to the given service.
     *
     * @param string $service
     * @return Future<ScanResult>
     */
    public function options(string $service): Future
    {
        $uri = sprintf('icap://%s%s', $this->config->host, $service);
        $request = new IcapRequest('OPTIONS', $uri);
        return $this->request($request);
    }

    /**
     * Scan a local file via RESPMOD.
     *
     * @param string $service
     * @param string $filePath
     * @return Future<ScanResult>
     * @throws \RuntimeException When the file cannot be opened
     */
    public function scanFile(string $service, string $filePath): Future
    {
        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open file');
        }
        $uri = sprintf('icap://%s%s', $this->config->host, $service);
        $request = new IcapRequest('RESPMOD', $uri, [], $stream);
        return $this->request($request);
    }

    /**
     * Scan a file using preview mode.
     *
     * @param string $service
     * @param string $filePath
     * @param int    $previewSize
     * @return Future<ScanResult>
     * @throws \RuntimeException When the file cannot be read
     */
    public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): Future
    {
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($service, $filePath, $previewSize): ScanResult {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \RuntimeException('Unable to read file');
            }

            $uri = sprintf('icap://%s%s', $this->config->host, $service);

            $previewBody = substr($content, 0, $previewSize);
            $previewReq = new IcapRequest('RESPMOD', $uri, ['Preview' => [(string) $previewSize]], $previewBody);
            $previewResult = $this->request($previewReq)->await();
            $decision = $this->previewStrategy->handlePreviewResponse($previewResult->getOriginalResponse());

            if ($decision === PreviewDecision::CONTINUE_SENDING) {
                $remaining = substr($content, $previewSize);
                $finalReq = new IcapRequest('RESPMOD', $uri, [], $remaining);
                return $this->request($finalReq)->await();
            }

            return $previewResult;
        });

        return $future;
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

        throw new IcapResponseException('Unexpected ICAP status: ' . $response->statusCode, $response->statusCode);
    }
}

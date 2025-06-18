<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\DefaultPreviewStrategy;

class IcapClient
{
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

    public static function forServer(string $host, int $port = 1344): self
    {
        return new self(new Config($host, $port), new SynchronousStreamTransport(), new RequestFormatter(), new ResponseParser());
    }

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
     * @return Future<IcapResponse>
     */
    public function request(IcapRequest $request): Future
    {
        return \Amp\async(function () use ($request) {
            $raw = $this->formatter->format($request);
            $responseString = $this->transport->request($this->config, $raw)->await();

            return $this->parser->parse($responseString);
        });
    }

    /**
     * @return Future<IcapResponse>
     */
    public function options(string $service): Future
    {
        $uri = sprintf('icap://%s%s', $this->config->host, $service);
        $request = new IcapRequest('OPTIONS', $uri);
        return $this->request($request);
    }

    /**
     * @return Future<IcapResponse>
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
     * @return Future<IcapResponse>
     */
    public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): Future
    {
        return \Amp\async(function () use ($service, $filePath, $previewSize) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \RuntimeException('Unable to read file');
            }

            $uri = sprintf('icap://%s%s', $this->config->host, $service);

            $previewBody = substr($content, 0, $previewSize);
            $previewReq = new IcapRequest('RESPMOD', $uri, ['Preview' => [(string) $previewSize]], $previewBody);
            $previewResponse = $this->request($previewReq)->await();

            $decision = $this->previewStrategy->handlePreviewResponse($previewResponse);

            if ($decision === PreviewDecision::CONTINUE_SENDING) {
                $remaining = substr($content, $previewSize);
                $finalReq = new IcapRequest('RESPMOD', $uri, [], $remaining);
                return $this->request($finalReq)->await();
            }

            return $previewResponse;
        });
    }
}

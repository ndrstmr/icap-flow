<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\DefaultPreviewStrategy;

/**
 * Simple synchronous wrapper around {@link IcapClient}.
 */
final class SynchronousIcapClient
{
    private IcapClient $asyncClient;

    /**
     * @param IcapClient $asyncClient Underlying asynchronous client
     */
    public function __construct(IcapClient $asyncClient)
    {
        $this->asyncClient = $asyncClient;
    }

    /**
     * Create a client with default configuration.
     */
    public static function create(): self
    {
        $asyncClient = new IcapClient(
            new Config('127.0.0.1'),
            new SynchronousStreamTransport(),
            new RequestFormatter(),
            new ResponseParser(),
            new DefaultPreviewStrategy(),
        );

        return new self($asyncClient);
    }

    /**
     * @param IcapRequest $request
     */
    public function request(IcapRequest $request): IcapResponse
    {
        return $this->asyncClient->request($request)->await();
    }

    /**
     * @param string $service
     */
    public function options(string $service): IcapResponse
    {
        return $this->asyncClient->options($service)->await();
    }

    /**
     * @throws \RuntimeException
     */
    public function scanFile(string $service, string $filePath): IcapResponse
    {
        return $this->asyncClient->scanFile($service, $filePath)->await();
    }

    /**
     * @throws \RuntimeException
     */
    public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): IcapResponse
    {
        return $this->asyncClient->scanFileWithPreview($service, $filePath, $previewSize)->await();
    }
}

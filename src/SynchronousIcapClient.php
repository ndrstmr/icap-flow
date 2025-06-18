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

final class SynchronousIcapClient
{
    private IcapClient $asyncClient;

    public function __construct(IcapClient $asyncClient)
    {
        $this->asyncClient = $asyncClient;
    }

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

    public function request(IcapRequest $request): IcapResponse
    {
        return $this->asyncClient->request($request)->await();
    }

    public function options(string $service): IcapResponse
    {
        return $this->asyncClient->options($service)->await();
    }

    public function scanFile(string $service, string $filePath): IcapResponse
    {
        return $this->asyncClient->scanFile($service, $filePath)->await();
    }

    public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): IcapResponse
    {
        return $this->asyncClient->scanFileWithPreview($service, $filePath, $previewSize)->await();
    }
}

<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;

final class SynchronousIcapClient
{
    private IcapClient $asyncClient;

    public function __construct(IcapClient $asyncClient)
    {
        $this->asyncClient = $asyncClient;
    }

    public function request(IcapRequest $request): IcapResponse
    {
        return Future::await($this->asyncClient->request($request));
    }

    public function options(string $service): IcapResponse
    {
        return Future::await($this->asyncClient->options($service));
    }

    public function scanFile(string $service, string $filePath): IcapResponse
    {
        return Future::await($this->asyncClient->scanFile($service, $filePath));
    }
}

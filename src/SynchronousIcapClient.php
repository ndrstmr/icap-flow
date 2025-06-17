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
}

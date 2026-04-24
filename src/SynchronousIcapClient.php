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

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\ScanResult;
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
    private IcapClientInterface $asyncClient;

    /**
     * @param IcapClientInterface $asyncClient Underlying asynchronous client
     */
    public function __construct(IcapClientInterface $asyncClient)
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
    public function request(IcapRequest $request): ScanResult
    {
        return $this->asyncClient->request($request)->await();
    }

    /**
     * @param string $service
     */
    public function options(string $service): ScanResult
    {
        return $this->asyncClient->options($service)->await();
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     * @throws \RuntimeException
     */
    public function scanFile(string $service, string $filePath, array $extraHeaders = []): ScanResult
    {
        return $this->asyncClient->scanFile($service, $filePath, $extraHeaders)->await();
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     * @throws \RuntimeException
     */
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        int $previewSize = 1024,
        array $extraHeaders = [],
    ): ScanResult {
        return $this->asyncClient->scanFileWithPreview($service, $filePath, $previewSize, $extraHeaders)->await();
    }
}

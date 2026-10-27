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
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\DefaultPreviewStrategy;

/**
 * Simple synchronous wrapper around {@link IcapClient}.
 *
 * Each call is one operation of the wrapped client and ends at the latest after its `Config::getOperationTimeout()`
 * (60 seconds by default), with an {@see \Ndrstmr\Icap\Exception\IcapTimeoutException}: raise it for large
 * files or a slow scanner. With the synchronous transport the waits of the socket are bounded by it, but code that
 * does not return (a request-body producer) is only noticed afterwards.
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

    public function request(IcapRequest $request, ?Cancellation $cancellation = null): ScanResult
    {
        return $this->asyncClient->request($request, $cancellation)->await();
    }

    public function options(string $service, ?Cancellation $cancellation = null): IcapResponse
    {
        return $this->asyncClient->options($service, $cancellation)->await();
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     * @throws \Ndrstmr\Icap\Exception\IcapStorageException When the file cannot be opened, sized or read to its end
     */
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): ScanResult {
        return $this->asyncClient->scanFile($service, $filePath, $extraHeaders, $cancellation)->await();
    }

    /**
     * {@see DiscardingIcapClient::scanFileDiscardingBody()}, awaited.
     *
     * @param array<string, string|string[]> $extraHeaders
     * @throws \LogicException When the wrapped client does not implement {@see DiscardingIcapClient}
     */
    public function scanFileDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): ScanResult {
        return $this->discardingClient()->scanFileDiscardingBody($service, $filePath, $maxResponseBytes, $extraHeaders, $cancellation)->await();
    }

    /**
     * {@see DiscardingIcapClient::scanFileWithPreviewDiscardingBody()}, awaited.
     *
     * @param array<string, string|string[]> $extraHeaders
     * @throws \LogicException When the wrapped client does not implement {@see DiscardingIcapClient}
     */
    public function scanFileWithPreviewDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): ScanResult {
        return $this->discardingClient()->scanFileWithPreviewDiscardingBody($service, $filePath, $maxResponseBytes, $previewSize, $extraHeaders, $cancellation)->await();
    }

    private function discardingClient(): DiscardingIcapClient
    {
        return $this->asyncClient instanceof DiscardingIcapClient
            ? $this->asyncClient
            : throw new \LogicException($this->asyncClient::class . ' cannot discard the body of a response; it does not implement ' . DiscardingIcapClient::class . '.');
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     * @throws \Ndrstmr\Icap\Exception\IcapStorageException When the file cannot be opened, sized or read to its end
     */
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): ScanResult {
        return $this->asyncClient->scanFileWithPreview($service, $filePath, $previewSize, $extraHeaders, $cancellation)->await();
    }
}

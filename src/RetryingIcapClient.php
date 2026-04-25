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
use Closure;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapServerException;

use function Amp\delay;

/**
 * Retry decorator around any {@see IcapClientInterface}.
 *
 * Retries only on {@see IcapServerException} (ICAP 5xx) — those are
 * transient by RFC 3507 §4.3.3. Client errors (4xx, parse failures,
 * connection errors) propagate immediately; retrying them would either
 * be useless (the request is malformed) or compound a hostile
 * environment problem (network is down).
 *
 * Backoff is exponential: `baseDelaySeconds * backoffFactor^(attempt-1)`,
 * capped at `maxDelaySeconds`. With the defaults (100 ms / 2x / cap 5 s)
 * three retries wait 0.1 / 0.2 / 0.4 s.
 *
 * Example wiring:
 *
 *     $client = new RetryingIcapClient(
 *         IcapClient::create(),
 *         maxAttempts: 3,
 *         baseDelaySeconds: 0.1,
 *         maxDelaySeconds: 5.0,
 *     );
 */
final class RetryingIcapClient implements IcapClientInterface
{
    /** @var Closure(float): void */
    private Closure $sleeper;

    /**
     * @param IcapClientInterface           $inner            Wrapped client (typically IcapClient)
     * @param int                           $maxAttempts      Maximum total attempts (initial + retries); must be >= 1
     * @param float                         $baseDelaySeconds Initial delay before the first retry
     * @param float                         $maxDelaySeconds  Cap on any individual retry delay
     * @param float                         $backoffFactor    Multiplier applied between attempts
     * @param (Closure(float): void)|null   $sleeper          Test seam — replaces Amp\delay() with a callable
     */
    public function __construct(
        private IcapClientInterface $inner,
        private int $maxAttempts = 3,
        private float $baseDelaySeconds = 0.1,
        private float $maxDelaySeconds = 5.0,
        private float $backoffFactor = 2.0,
        ?Closure $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1, got: ' . $maxAttempts);
        }
        if ($baseDelaySeconds < 0.0) {
            throw new \InvalidArgumentException('baseDelaySeconds must be >= 0, got: ' . $baseDelaySeconds);
        }
        if ($maxDelaySeconds < 0.0) {
            throw new \InvalidArgumentException('maxDelaySeconds must be >= 0, got: ' . $maxDelaySeconds);
        }
        if ($backoffFactor <= 0.0) {
            throw new \InvalidArgumentException('backoffFactor must be > 0, got: ' . $backoffFactor);
        }

        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0.0) {
                delay($seconds);
            }
        };
    }

    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        return $this->withRetry(fn (): Future => $this->inner->request($request, $cancellation));
    }

    #[\Override]
    public function options(string $service, ?Cancellation $cancellation = null): Future
    {
        return $this->withRetry(fn (): Future => $this->inner->options($service, $cancellation));
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     */
    #[\Override]
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        return $this->withRetry(fn (): Future => $this->inner->scanFile($service, $filePath, $extraHeaders, $cancellation));
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     */
    #[\Override]
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        int $previewSize = 1024,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        return $this->withRetry(
            fn (): Future => $this->inner->scanFileWithPreview($service, $filePath, $previewSize, $extraHeaders, $cancellation),
        );
    }

    /**
     * @param Closure(): Future<ScanResult> $operation
     * @return Future<ScanResult>
     */
    private function withRetry(Closure $operation): Future
    {
        /** @var Future<ScanResult> $future */
        $future = \Amp\async(function () use ($operation): ScanResult {
            // The loop runs at least once because maxAttempts >= 1 is
            // enforced in the ctor, so the final throw always has a
            // captured exception to re-raise.
            /** @var IcapServerException $lastException */
            $lastException = new IcapServerException('unreachable');

            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                try {
                    return $operation()->await();
                } catch (IcapServerException $e) {
                    $lastException = $e;
                    if ($attempt === $this->maxAttempts) {
                        break;
                    }
                    ($this->sleeper)($this->backoffFor($attempt));
                }
            }

            throw $lastException;
        });

        return $future;
    }

    private function backoffFor(int $attempt): float
    {
        $delay = $this->baseDelaySeconds * ($this->backoffFactor ** ($attempt - 1));
        return min($delay, $this->maxDelaySeconds);
    }
}

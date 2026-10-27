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
use Amp\CancelledException;
use Amp\Future;
use Closure;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapServerException;

use function Amp\async;
use function Amp\delay;

/**
 * Retry decorator around any {@see IcapClientInterface}.
 *
 * **What is repeated (N03).** Only {@see options()}, and only after an
 * {@see IcapServerException} (ICAP 5xx), which is transient by RFC 3507
 * §4.3.3: OPTIONS has no body and changes nothing, so a second one is safe.
 * {@see request()}, {@see scanFile()}, {@see scanFileWithPreview()} and their discarding
 * variants get one attempt. A 5xx, or a write that failed, does not prove that the server did
 * not process any of the bytes it was sent, and nothing here can send an upload
 * again (a stream that was read cannot be read again, and a file may have
 * changed); an application that knows its scans are repeatable repeats them
 * itself. Client errors (4xx), parse failures, connection errors and timeouts
 * propagate at once: retrying them would be useless or would add to a
 * hostile environment.
 *
 * **Backoff.** Exponential with full jitter: the pause before the next attempt
 * is a random share, from nothing up to all of it, of
 * `baseDelaySeconds * backoffFactor^(attempt-1)`, capped at `maxDelaySeconds`,
 * so that many clients do not retry in step. With the defaults (100 ms / 2x /
 * cap 5 s) the pauses are at most 0.1 / 0.2 / 0.4 s. The pause ends with the
 * caller's cancellation and with the end of the operation, and even a pause of
 * 0 seconds hands the event loop over once.
 *
 * **One budget (M10).** `operationTimeout` (60 seconds by default) is the whole
 * of a call, attempts and pauses together, on a monotonic clock. It begins when
 * the method is called, is not restarted by an attempt, and is handed to the
 * inner client as its cancellation: an inner {@see IcapClient} ends with it (one
 * with a shorter timeout of its own keeps it; none can extend this one). It ends
 * the call with an {@see \Ndrstmr\Icap\Exception\IcapTimeoutException}; the
 * caller's cancellation stays an `Amp\CancelledException`. An inner client that
 * ignores its cancellation is waited for, and a result that comes in after the
 * end is not returned.
 *
 * Example wiring:
 *
 *     $client = new RetryingIcapClient(
 *         IcapClient::create(),
 *         maxAttempts: 3,
 *         baseDelaySeconds: 0.1,
 *         maxDelaySeconds: 5.0,
 *         operationTimeout: 30.0,
 *     );
 */
final class RetryingIcapClient implements IcapClientInterface, DiscardingIcapClient
{
    /** @var (Closure(float): void)|null */
    private ?Closure $sleeper;

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param IcapClientInterface           $inner            Wrapped client (typically IcapClient)
     * @param int                           $maxAttempts      Maximum total attempts (initial + retries); must be >= 1
     * @param float                         $baseDelaySeconds Initial delay before the first retry; finite, 0 or more
     * @param float                         $maxDelaySeconds  Cap on any individual retry delay; finite, 0 or more
     * @param float                         $backoffFactor    Multiplier applied between attempts; finite and above 0
     * @param (Closure(float): void)|null   $sleeper          Test seam — replaces the pause with a callable. It is not interrupted
     *                                                        from outside; the end of the operation is looked at before and after it
     * @param float                         $operationTimeout Seconds one call may take in all, attempts and pauses together;
     *                                                        finite and above 0
     * @param (Closure(): float)|null       $random           Test seam — the random share of the jitter, from 0 to 1 (default: mt_rand)
     */
    public function __construct(
        private IcapClientInterface $inner,
        private int $maxAttempts = 3,
        private float $baseDelaySeconds = 0.1,
        private float $maxDelaySeconds = 5.0,
        private float $backoffFactor = 2.0,
        ?Closure $sleeper = null,
        private float $operationTimeout = 60.0,
        ?Closure $random = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1, got: ' . $maxAttempts);
        }
        // var_export() for the floats: PHP 8.5 warns when a NAN is coerced to a string. NAN and INF pass a
        // comparison with 0 and would turn the backoff into NAN, so finiteness is checked first.
        if (!is_finite($baseDelaySeconds) || $baseDelaySeconds < 0.0) {
            throw new \InvalidArgumentException('baseDelaySeconds must be a finite number >= 0, got: ' . var_export($baseDelaySeconds, true));
        }
        if (!is_finite($maxDelaySeconds) || $maxDelaySeconds < 0.0) {
            throw new \InvalidArgumentException('maxDelaySeconds must be a finite number >= 0, got: ' . var_export($maxDelaySeconds, true));
        }
        if (!is_finite($backoffFactor) || $backoffFactor <= 0.0) {
            throw new \InvalidArgumentException('backoffFactor must be a finite number > 0, got: ' . var_export($backoffFactor, true));
        }
        if (!is_finite($operationTimeout) || $operationTimeout <= 0.0) {
            throw new \InvalidArgumentException('operationTimeout must be a finite number of seconds above 0, got: ' . var_export($operationTimeout, true));
        }

        $this->sleeper = $sleeper;
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    #[\Override]
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future
    {
        return $this->once(
            $cancellation,
            fn (OperationDeadline $deadline): ScanResult => $this->inner->request($request, $deadline)->await(),
        );
    }

    /**
     * The one operation that is repeated: after an ICAP 5xx, up to `maxAttempts` attempts in all, inside one
     * `operationTimeout`.
     */
    #[\Override]
    public function options(string $service, ?Cancellation $cancellation = null): Future
    {
        $deadline = OperationDeadline::begin($cancellation, $this->operationTimeout);

        return $this->run($deadline, function () use ($service, $deadline): IcapResponse {
            $last = null;
            for ($attempt = 1; ; $attempt++) {
                // No new attempt once the budget is gone, whatever the pause before it did.
                $deadline->checkpoint($last);

                try {
                    return $this->inner->options($service, $deadline)->await();
                } catch (IcapServerException $e) {
                    $last = $e;
                    if ($attempt >= $this->maxAttempts) {
                        throw $e;
                    }
                    $this->pause($this->pauseAfter($attempt), $deadline, $e);
                }
            }
        });
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
        return $this->once(
            $cancellation,
            fn (OperationDeadline $deadline): ScanResult => $this->inner->scanFile($service, $filePath, $extraHeaders, $deadline)->await(),
        );
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     */
    #[\Override]
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        return $this->once(
            $cancellation,
            fn (OperationDeadline $deadline): ScanResult => $this->inner->scanFileWithPreview($service, $filePath, $previewSize, $extraHeaders, $deadline)->await(),
        );
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     *
     * @throws \LogicException When the wrapped client does not implement {@see DiscardingIcapClient}
     */
    #[\Override]
    public function scanFileDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $inner = $this->discardingInner();

        return $this->once(
            $cancellation,
            fn (OperationDeadline $deadline): ScanResult => $inner->scanFileDiscardingBody($service, $filePath, $maxResponseBytes, $extraHeaders, $deadline)->await(),
        );
    }

    /**
     * @param array<string, string|string[]> $extraHeaders
     *
     * @throws \LogicException When the wrapped client does not implement {@see DiscardingIcapClient}
     */
    #[\Override]
    public function scanFileWithPreviewDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future {
        $inner = $this->discardingInner();

        return $this->once(
            $cancellation,
            fn (OperationDeadline $deadline): ScanResult => $inner->scanFileWithPreviewDiscardingBody($service, $filePath, $maxResponseBytes, $previewSize, $extraHeaders, $deadline)->await(),
        );
    }

    private function discardingInner(): DiscardingIcapClient
    {
        return $this->inner instanceof DiscardingIcapClient
            ? $this->inner
            : throw new \LogicException($this->inner::class . ' cannot discard the body of a response; it does not implement ' . DiscardingIcapClient::class . '.');
    }

    /**
     * One attempt, inside the budget of the call.
     *
     * @template T
     *
     * @param Closure(OperationDeadline): T $operation
     *
     * @return Future<T>
     */
    private function once(?Cancellation $cancellation, Closure $operation): Future
    {
        $deadline = OperationDeadline::begin($cancellation, $this->operationTimeout);

        return $this->run($deadline, static fn (): mixed => $operation($deadline));
    }

    /**
     * Runs the operation in a fiber of its own, with the timer and the subscription of the budget set for exactly
     * as long as it runs. A result that comes in after the end of the budget is none.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return Future<T>
     */
    private function run(OperationDeadline $deadline, Closure $operation): Future
    {
        /** @var Future<T> $future */
        $future = async(static function () use ($deadline, $operation): mixed {
            try {
                $deadline->checkpoint();
                $result = $operation();
                $deadline->checkpoint();

                return $result;
            } catch (\Throwable $e) {
                throw $deadline->outcome($e);
            } finally {
                $deadline->close();
            }
        });

        return $future;
    }

    /**
     * The pause between two attempts. It ends with the caller's cancellation and with the end of the operation (an
     * {@see \Ndrstmr\Icap\Exception\IcapTimeoutException} with the 5xx as its cause). A sleeper cannot be interrupted
     * from here, so the budget is looked at before it is called and, by the loop, before the next attempt.
     */
    private function pause(float $seconds, OperationDeadline $deadline, IcapServerException $cause): void
    {
        if ($this->sleeper !== null) {
            $deadline->checkpoint($cause);
            ($this->sleeper)($seconds);

            return;
        }

        try {
            // Also a pause of 0 seconds suspends once: a loop of attempts must leave the event loop its turns.
            delay($seconds, true, $deadline);
        } catch (CancelledException $e) {
            throw $deadline->outcome($e, $cause);
        }
    }

    /**
     * Full jitter: any pause from nothing up to the capped exponential one.
     */
    private function pauseAfter(int $attempt): float
    {
        $draw = ($this->random)();

        return $this->backoffCap($attempt) * (is_finite($draw) ? min(max($draw, 0.0), 1.0) : 1.0);
    }

    /**
     * `baseDelaySeconds * backoffFactor^(attempt-1)`, capped at `maxDelaySeconds`. The power overflows to INF for
     * a large attempt number, and INF is above every cap; a base of 0 is a pause of 0 before the product with INF
     * could become NAN.
     */
    private function backoffCap(int $attempt): float
    {
        if ($this->baseDelaySeconds === 0.0) {
            return 0.0;
        }

        return min($this->baseDelaySeconds * ($this->backoffFactor ** ($attempt - 1)), $this->maxDelaySeconds);
    }
}

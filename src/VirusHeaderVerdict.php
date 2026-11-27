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

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\DTO\ScanResult;
use Ndrstmr\Icap\Exception\IcapProtocolException;

/**
 * Fail-closed verdict for ICAP servers that report findings via configured virus headers and optional policy-block
 * headers (`docs/virus-header-verdict.md`).
 *
 * Unlike {@see CicapVerdict}, this verdict is not verified against a reference service: icap-flow tests these rules,
 * while the operator must verify that the target ICAP service actually scans uploads and emits the configured headers.
 *
 *  - Clean: only a `204` after the whole message was sent, carrying neither a configured virus header nor a configured
 *    block header. A `204` to a partial preview (`$complete === false`) or carrying verdict headers is an error.
 *  - Finding: a `200` or `206` carrying one non-empty threat name across all configured virus headers (identical
 *    repeats allowed; empty or differing values are an error).
 *  - Policy block: a `200` or `206` without virus headers and with one non-empty reason across all configured block
 *    headers. Returned with `isInfected() === true`, `getVirusName() === null`, and `getBlockReason()` set.
 *  - Error: a `200` or `206` with neither virus nor block headers (unmodified message), or a `100 Continue`
 *    carrying a configured virus or block header.
 */
final readonly class VirusHeaderVerdict implements VerdictInterface
{
    /** @var list<string> */
    private array $virusHeaders;

    /** @var list<string> */
    private array $blockHeaders;

    /**
     * @param string       $service      The service this verdict is bound to: an absolute path with an optional query (`/avscan`)
     * @param list<string> $virusHeaders Non-empty list of ICAP header names reporting a detected threat
     * @param list<string> $blockHeaders Optional list of ICAP header names reporting a policy block without a threat name
     *
     * @throws \InvalidArgumentException When the service or a header name list is invalid
     */
    public function __construct(
        private string $service,
        array $virusHeaders = ['X-Virus-Name'],
        array $blockHeaders = [],
    ) {
        IcapUri::assertService($service);
        $this->virusHeaders = HeaderFields::checkedNames($virusHeaders, 'virusHeaders');
        $this->blockHeaders = HeaderFields::checkedNames($blockHeaders, 'blockHeaders', allowEmpty: true);
    }

    #[\Override]
    public function service(): string
    {
        return $this->service;
    }

    /**
     * @throws IcapProtocolException When the answer does not prove a valid verdict or contradicts itself
     */
    #[\Override]
    public function interpret(IcapResponse $response, bool $complete): ScanResult
    {
        $status = $response->statusCode;
        $virusValues = self::rawValues($response->headers, $this->virusHeaders);
        $blockValues = self::rawValues($response->headers, $this->blockHeaders);

        if ($status === 204) {
            if ($virusValues !== [] || $blockValues !== []) {
                throw self::contradiction($status);
            }
            if (!$complete) {
                throw new IcapProtocolException(
                    'The service ended the exchange with a clean answer before it had the whole file: it did not scan the file.',
                    $status,
                );
            }

            return new ScanResult(false, null, $response);
        }

        if ($virusValues !== []) {
            $threat = self::uniqueNonEmpty($virusValues, 'virus', $status);

            return new ScanResult(true, $threat, $response);
        }

        if ($blockValues !== []) {
            $reason = self::uniqueNonEmpty($blockValues, 'block', $status);

            return new ScanResult(true, null, $response, $reason);
        }

        $page = $response->encapsulatedStatus;
        throw new IcapProtocolException(
            $page !== null && $page >= 400
                ? sprintf('The service answered with an error or block page (HTTP %d) and no configured virus or block header.', $page)
                : 'The service sent the message back without a configured virus or block header although it may answer 204: it did not scan the file.',
            $status,
        );
    }

    /**
     * @throws IcapProtocolException When the `100 Continue` carries a configured virus or block header
     */
    #[\Override]
    public function assertContinuation(IcapResponse $response): void
    {
        if (self::rawValues($response->headers, $this->virusHeaders) !== []
            || self::rawValues($response->headers, $this->blockHeaders) !== []) {
            throw self::contradiction($response->statusCode);
        }
    }

    /**
     * Collects every value of every named header across all case spellings.
     *
     * @param array<string, string[]> $headers
     * @param list<string>            $names
     *
     * @return list<string>
     */
    private static function rawValues(array $headers, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            foreach (HeaderFields::values($headers, $name) as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Ensures every present value is non-empty after trimming OWS and all values agree.
     *
     * @param non-empty-list<string> $values
     *
     * @throws IcapProtocolException When a value is empty or values disagree
     */
    private static function uniqueNonEmpty(array $values, string $kind, int $status): string
    {
        $trimmed = [];
        foreach ($values as $value) {
            $clean = trim($value, " \t");
            if ($clean === '') {
                throw new IcapProtocolException(sprintf('A %s header of the response is empty.', $kind), $status);
            }
            $trimmed[] = $clean;
        }

        $unique = array_values(array_unique($trimmed));
        if (count($unique) > 1) {
            throw new IcapProtocolException(sprintf('The %s headers of the response report conflicting values.', $kind), $status);
        }

        return $unique[0];
    }

    private static function contradiction(int $status): IcapProtocolException
    {
        return new IcapProtocolException('The verdict headers of the response contradict its status.', $status);
    }
}

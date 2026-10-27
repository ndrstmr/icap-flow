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
 * The verified mode for the c-icap `virus_scan` service with ClamAV (c-icap 0.6.3, c-icap-modules 0.5.7, ClamAV 1.4.3;
 * docs/cicap-clamav.md): it reads an answer of that service as the service means it, and an answer that does not show
 * that the file was scanned is an error, never a clean result. Pass it to {@see IcapClient} to turn the mode on.
 *
 * It is made for one service, given as the path and query a scan names it by (`/avscan`): the client scans with no other
 * service while the mode is on, and takes the endpoint from the {@see Config}. The service has to be set up as the
 * reference service is: the answers cannot show whether it scans every type of file.
 *
 *  - Infection: `X-Infection-Found: Type=n; Resolution=n; Threat=name;` and `X-Violations-Found: count - name
 *    problem-id resolution-id ...` (folded over several lines), read by value and count, whatever the spelling of the
 *    names and whatever the header list of the {@see Config}. The result names the threat, not the text of the header.
 *    A 200 or 206 with an infection is a finding, also as the answer to a preview. Headers that contradict each other
 *    or a 204, or that are malformed, are an error, and so is a 100 Continue that carries any of that.
 *  - Not scanned: ClamAV reports a file over its limits (`AlertExceedsMax`) and an encrypted archive or document
 *    (`AlertEncrypted`) with a name of its own, `Heuristics.Limits.Exceeded...` and `Heuristics.Encrypted...`. That says
 *    that the file was not looked into: an error, not a finding. Every other name is a finding.
 *  - Clean: only a 204 to a file that was sent whole (the preview held all of it, or the service asked for the rest with
 *    100 Continue). For a type it does not scan the service answers the preview of a file that is not all sent with a
 *    204 at once, so that is an error, and so is a 200 or 206 without an infection (the message sent back, a block or
 *    error page).
 *
 * What an answer cannot show is whether the service scans everything it is sent: a service that skips a type also
 * answers a file that the preview holds, and sometimes a file sent without a preview, with the same 204 as for a clean
 * scan. That is for the configuration of the service.
 */
final readonly class CicapVerdict
{
    /**
     * @param string $service The service the mode is for: an absolute path with an optional query, as a scan names it
     *
     * @throws \InvalidArgumentException When the service is not that
     */
    public function __construct(public string $service)
    {
        IcapUri::assertService($service);
    }

    /**
     * The result of the final answer to a message that was sent whole, or of an answer that ends a preview that holds
     * only the start of the file.
     *
     * @param bool $complete whether the service had the whole file when it answered
     *
     * @throws IcapProtocolException When the answer does not show that the file was scanned, or contradicts itself
     */
    public function interpret(IcapResponse $response, bool $complete): ScanResult
    {
        $status = $response->statusCode;
        if ($status !== 200 && $status !== 204 && $status !== 206) {
            throw new IcapProtocolException('Unexpected ICAP status: ' . $status, $status);
        }

        $threats = $this->threats($response);
        if ($status === 204) {
            if ($threats !== []) {
                throw self::contradiction($status);
            }
            if (!$complete) {
                throw new IcapProtocolException('The service ended the exchange with a clean answer before it had the whole file: it did not scan the file.', $status);
            }

            return new ScanResult(false, null, $response);
        }

        if ($threats !== []) {
            $findings = array_values(array_filter($threats, static fn (string $threat): bool => !self::isDiagnostic($threat)));
            if ($findings === []) {
                throw new IcapProtocolException('The service reports that it did not look into the whole file (a limit of the engine, or an encrypted file): it did not scan the file.', $status);
            }

            return new ScanResult(true, $findings[0], $response);
        }

        $page = $response->encapsulatedStatus;
        throw new IcapProtocolException(
            $page !== null && $page >= 400
                ? sprintf('The service answered with an error or block page (HTTP %d) and no infection signal.', $page)
                : 'The service sent the message back without an infection signal although it may answer 204: it did not scan the file.',
            $status,
        );
    }

    /**
     * A 100 Continue asks for the rest of the file and decides nothing. One that reports an infection, or carries an
     * infection header that is malformed, is none: the file is not sent on.
     *
     * @throws IcapProtocolException When the 100 contradicts itself
     */
    public function assertContinuation(IcapResponse $response): void
    {
        if ($this->threats($response) !== []) {
            throw self::contradiction($response->statusCode);
        }
    }

    /**
     * The threats the headers report, or none.
     *
     * @return list<string>
     *
     * @throws IcapProtocolException When a header is malformed or the headers contradict each other
     */
    private function threats(IcapResponse $response): array
    {
        $status = $response->statusCode;

        $named = [];
        foreach (HeaderFields::values($response->headers, 'X-Infection-Found') as $value) {
            if (preg_match('/\AType=[0-9]+; *Resolution=[0-9]+; *Threat=([^\s;].*?);?\z/', $value, $m) !== 1) {
                throw self::malformed($status);
            }
            $named[] = $m[1];
        }

        $listed = [];
        $counted = HeaderFields::values($response->headers, 'X-Violations-Found');
        foreach ($counted as $value) {
            $listed = [...$listed, ...self::violations($value, $status)];
        }

        // A count of none says that there is nothing to report, and the list is the threats of the other header.
        $none = $counted !== [] && $listed === [];
        $other = $listed !== [] && array_diff($named, $listed) !== [];
        if ($named !== [] && ($none || $other)) {
            throw self::contradiction($status);
        }

        return array_values(array_unique([...$named, ...$listed]));
    }

    /**
     * The names ClamAV 1.4.3 gives to a limit it ran into and to an encrypted file it could not look into
     * (`AlertExceedsMax`, `AlertEncrypted` of clamd.conf), and nothing else.
     */
    private static function isDiagnostic(string $threat): bool
    {
        return preg_match('/\AHeuristics\.(?:Limits\.Exceeded|Encrypted)(?:\.|\z)/', $threat) === 1;
    }

    /**
     * The names in `count - name problem-id resolution-id ...` (the lines of the header, folded into one), exactly as
     * many as the count says.
     *
     * @return list<string>
     *
     * @throws IcapProtocolException When the value is not that structure
     */
    private static function violations(string $value, int $status): array
    {
        $tokens = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === [] || preg_match('/\A[0-9]{1,6}\z/', $tokens[0]) !== 1) {
            throw self::malformed($status);
        }
        $count = (int) $tokens[0];
        if (count($tokens) !== 1 + 4 * $count) {
            throw self::malformed($status);
        }

        $names = [];
        for ($i = 0; $i < $count; $i++) {
            [, $name, $problem, $resolution] = array_slice($tokens, 1 + 4 * $i, 4);
            if (!ctype_digit($problem) || !ctype_digit($resolution)) {
                throw self::malformed($status);
            }
            $names[] = $name;
        }

        return $names;
    }

    private static function malformed(int $status): IcapProtocolException
    {
        return new IcapProtocolException('An infection header of the response is malformed.', $status);
    }

    private static function contradiction(int $status): IcapProtocolException
    {
        return new IcapProtocolException('The infection headers of the response contradict each other or its status.', $status);
    }
}

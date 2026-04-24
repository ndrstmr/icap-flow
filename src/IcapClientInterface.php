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
use Ndrstmr\Icap\DTO\ScanResult;

/**
 * Public contract for ICAP clients.
 *
 * Implementations are async-native and return Amp Futures. The synchronous
 * decorator {@see SynchronousIcapClient} awaits those Futures behind a
 * blocking facade.
 *
 * Every method takes an optional {@see Cancellation} so a caller can
 * abort an in-flight scan from the outside (e.g. when an upstream HTTP
 * upload has been aborted). The user-supplied token is combined with the
 * per-request timeout cancellation derived from
 * {@see Config::getStreamTimeout()}; whichever fires first wins.
 */
interface IcapClientInterface
{
    /**
     * Send a raw ICAP request.
     *
     * @return Future<ScanResult>
     */
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future;

    /**
     * Issue an OPTIONS request against the given service.
     *
     * @return Future<ScanResult>
     */
    public function options(string $service, ?Cancellation $cancellation = null): Future;

    /**
     * Scan a local file via RESPMOD.
     *
     * @param array<string, string|string[]> $extraHeaders Extra ICAP request
     *        headers, e.g. `['X-Client-IP' => '203.0.113.5']`. Library-managed
     *        headers (`Host`, `Encapsulated`, `Connection`) always take precedence.
     * @return Future<ScanResult>
     */
    public function scanFile(
        string $service,
        string $filePath,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future;

    /**
     * Scan a file using preview mode, streaming the remainder on demand.
     *
     * @param array<string, string|string[]> $extraHeaders See {@see scanFile()}.
     *        `Preview` and `Allow` are library-managed and cannot be
     *        overridden via this parameter.
     * @return Future<ScanResult>
     */
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        int $previewSize = 1024,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future;
}

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

/**
 * Public contract for ICAP clients.
 *
 * Implementations are async-native and return Amp Futures. The synchronous
 * decorator {@see SynchronousIcapClient} awaits those Futures behind a
 * blocking facade.
 *
 * Every method takes an optional {@see Cancellation} so a caller can
 * abort an in-flight scan from the outside (e.g. when an upstream HTTP
 * upload has been aborted). A call ends at the first of three things: the
 * caller's cancellation, which stays an `Amp\CancelledException` and wins
 * when more than one applies; the end of the operation
 * ({@see Config::getOperationTimeout()}, fixed when the method is called and
 * covering every phase, and in the retry decorator every attempt and
 * pause); and the limit of a phase ({@see Config::getStreamTimeout()}, the
 * TLS handshake, the start of the answer). The last two are an
 * {@see \Ndrstmr\Icap\Exception\IcapTimeoutException}. An expired call
 * closes its socket and its file handle. Code that holds the event loop and
 * does not return (a request-body producer, a slow cache) cannot be
 * interrupted; the end is looked at when control comes back, and a result
 * that comes in after it is not returned.
 */
interface IcapClientInterface
{
    /**
     * Send a raw ICAP request.
     *
     * A stream resource in the encapsulated body is read, never closed: it
     * stays the caller's, whatever the outcome.
     *
     * @return Future<ScanResult>
     */
    public function request(IcapRequest $request, ?Cancellation $cancellation = null): Future;

    /**
     * Issue an OPTIONS request against the given service. Returns the raw
     * {@see IcapResponse} on success so callers can inspect capability
     * headers (`Preview`, `Options-TTL`, `Methods`, `Allow`, `Service`,
     * `ISTag`, …) directly. Only a `200` is an answer to OPTIONS, whether it
     * comes from the server or from the cache: 4xx throws
     * {@see IcapClientException}, 5xx throws {@see IcapServerException}, and
     * every other status, including `100 Continue`, `204` and `206`, throws
     * {@see IcapProtocolException}.
     *
     * @return Future<IcapResponse>
     */
    public function options(string $service, ?Cancellation $cancellation = null): Future;

    /**
     * Scan a local file via RESPMOD.
     *
     * The file is opened inside the returned Future and closed when the
     * operation ends, whatever the outcome. Its size is taken from the open
     * handle, and the body goes out with exactly that many bytes. The Future
     * fails with a \RuntimeException when the file cannot be opened, sized or
     * read to its end, or has another size than its handle reported; nothing
     * is sent as if it were an empty, shorter or longer file.
     *
     * @param array<string, string|string[]> $extraHeaders Extra ICAP request
     *        headers, e.g. `['X-Client-IP' => '203.0.113.5']`. `Host` and
     *        `Encapsulated` are always set by the client; caller values for
     *        them are dropped in any spelling. No preview is sent, so a
     *        caller `Preview` is dropped as well; `Allow` passes.
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
     * When $previewSize is null the client queries the OPTIONS cache for
     * the server's advertised `Preview` header (RFC 3507 §4.10.2) and
     * uses that value. Falls back to 1024 when the cache has no entry
     * or the response carries no `Preview` header.
     *
     * The preview is 0 to 1 MiB (1048576 bytes). `0` sends the headers
     * alone. An explicit size outside that range throws an
     * \InvalidArgumentException before the file is opened; a cached value
     * above it is clamped to it, and a cached value that is not a
     * non-negative decimal integer throws an
     * {@see \Ndrstmr\Icap\Exception\IcapMalformedResponseException}. The
     * file is handled as in {@see scanFile()}, `ieof` is sent only
     * after the end of the file was observed, and the rest of the file goes
     * out with the length its envelope announced.
     *
     * @param array<string, string|string[]> $extraHeaders See {@see scanFile()}.
     *        `Preview` and `Allow` are library-managed and cannot be
     *        overridden via this parameter.
     * @return Future<ScanResult>
     */
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future;
}

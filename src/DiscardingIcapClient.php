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
use Ndrstmr\Icap\DTO\ScanResult;

/**
 * Optional capability of a client: the scan helpers for a caller who needs the result and the metadata
 * of the response, not its body.
 *
 * A server that is not offered `Allow: 204` has to send the whole message back (RFC 3507 §4.6), so the
 * answer to a large file is as large as the file. The methods of this interface receive each response of the
 * exchange as it arrives, check it to its last byte, count the body and drop it: what the client holds does not
 * depend on its size, and the result has an empty body and its length in
 * {@see \Ndrstmr\Icap\DTO\IcapResponse::$discardedBodyBytes}. Request, checks and verdict are those of
 * {@see IcapClientInterface}; `Allow: 204` is not added.
 *
 * `$maxResponseBytes` has no default: it bounds the bytes of each response (head, headers, chunk framing, body and
 * trailer) in place of `Config::getMaxResponseSize()`. The stream timeout is the time a whole response may take once
 * it has begun, so it and the operation timeout have to allow for the transfer of a large one.
 *
 * A client whose transport or parser cannot discard throws a \LogicException from these methods, before it opens a
 * file or a connection; so does a client with a preview strategy of its own, which may judge the body of the answer
 * to the preview. Bytes behind a response, already read, are an {@see \Ndrstmr\Icap\Exception\IcapMalformedResponseException}.
 */
interface DiscardingIcapClient
{
    /**
     * {@see IcapClientInterface::scanFile()} with the body of the response discarded.
     *
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     *
     * @throws \InvalidArgumentException When the budget is below 1
     * @throws \LogicException           When the transport or the parser cannot discard
     */
    public function scanFileDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future;

    /**
     * {@see IcapClientInterface::scanFileWithPreview()} with the body of every response of the exchange discarded,
     * the final answer after `100 Continue` included.
     *
     * @param array<string, string|string[]> $extraHeaders
     *
     * @return Future<ScanResult>
     *
     * @throws \InvalidArgumentException When the budget is below 1, or $previewSize is outside 0..1 MiB
     * @throws \LogicException           When the transport or the parser cannot discard, or the preview strategy
     *                                   is not that of the library
     */
    public function scanFileWithPreviewDiscardingBody(
        string $service,
        string $filePath,
        int $maxResponseBytes,
        ?int $previewSize = null,
        array $extraHeaders = [],
        ?Cancellation $cancellation = null,
    ): Future;
}

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
 * Contract for interpreting ICAP scan answers fail-closed for one configured service.
 *
 * When passed to {@see IcapClient}, the client scans with {@see service()} only, refuses custom preview strategies,
 * sends `Allow: 204` on whole-file scans, rejects non-verdict statuses (`4xx`, `5xx`, `100` outside a preview, and
 * unexpected codes) itself, and delegates every `100 Continue` on a partial preview and every final `200`, `204` or
 * `206` scan response to this contract.
 */
interface VerdictInterface
{
    /**
     * The service this verdict is bound to: an absolute path with an optional query, as a scan names it (`/avscan`).
     */
    public function service(): string;

    /**
     * Interpret a final `200`, `204` or `206` response to a message that was sent whole, or an answer that ends a
     * partial preview. The client rejects every other status before calling this method.
     *
     * @param bool $complete Whether the service had the whole message when it answered
     *
     * @throws IcapProtocolException When the answer does not prove a valid verdict or contradicts itself
     */
    public function interpret(IcapResponse $response, bool $complete): ScanResult;

    /**
     * Validate a `100 Continue` response to a partial preview before the client streams the remainder of the file.
     * The client only calls this method for status `100` during a partial preview exchange.
     *
     * @throws IcapProtocolException When the `100 Continue` carries contradictory or malformed verdict headers
     */
    public function assertContinuation(IcapResponse $response): void;
}

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

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * Optional capability of a {@see TransportSession}: to read a response without keeping it, as
 * {@see DiscardingTransport} does for a whole exchange.
 */
interface DiscardingTransportSession extends TransportSession
{
    /**
     * Like {@see TransportSession::readResponse()}, with a response that is not kept: its body is counted in
     * {@see IcapResponse::$discardedBodyBytes}. Bytes already read behind the response cause an
     * {@see \Ndrstmr\Icap\Exception\IcapMalformedResponseException} and end the session, as does a response that is
     * illegal, cut off or over the budget. `$maxResponseBytes` is the budget for the bytes of the whole response in
     * place of `Config::getMaxResponseSize()`.
     */
    public function readResponseDiscardingBody(int $maxResponseBytes): IcapResponse;
}

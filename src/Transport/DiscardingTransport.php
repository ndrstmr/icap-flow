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

use Amp\Cancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * Optional capability of a transport: to receive a response without keeping it.
 *
 * {@see TransportInterface::request()} returns the whole response as a string, which bounds it by what fits in
 * memory. A transport that implements this interface reads the response as it arrives, checks it to its last byte
 * and keeps neither its frame nor its body ({@see IcapResponse::$discardedBodyBytes}). The scan helpers use it when
 * their caller asks for the body to be discarded ({@see \Ndrstmr\Icap\DiscardingIcapClient}); a transport without it
 * is refused there, not asked to buffer. A transport that is also a {@see SessionAwareTransport} opens sessions that
 * are {@see DiscardingTransportSession}s.
 */
interface DiscardingTransport extends TransportInterface
{
    /**
     * Like {@see TransportInterface::request()}, with a response that is not kept. Framing, windows, cancellation and
     * the closing of the socket are the same. `$maxResponseBytes` is the budget for the bytes of the whole response in
     * place of `Config::getMaxResponseSize()`; a response over it, or an illegal one, fails with an
     * {@see \Ndrstmr\Icap\Exception\IcapMalformedResponseException}. The limits for header lines and their number are
     * those of the configuration.
     *
     * @param iterable<string> $rawRequest
     *
     * @return \Amp\Future<IcapResponse>
     */
    public function requestDiscardingBody(
        Config $config,
        iterable $rawRequest,
        int $maxResponseBytes,
        ?Cancellation $cancellation = null,
    ): \Amp\Future;
}

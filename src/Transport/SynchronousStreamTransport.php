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
 * Blocking transport using plain PHP stream sockets, for CLI and test usage. TLS is not supported; production
 * deployments use {@see AsyncAmpTransport} (TLS, pooling, cancellation that interrupts a wait).
 *
 * Every exchange runs on a {@see SynchronousStreamSession}, which holds the limits, the cancellation and the framing.
 * A request is a session of its own that is closed after its response, and its write keeps the limit of this
 * transport: `streamTimeout` is the time the peer may go without taking a byte. {@see openSession()} hands the session
 * out for an exchange of more than one round trip, such as a preview that the server answers with `100 Continue`
 * (RFC 3507 §4.5); each of its writes has one window for the whole call ({@see TransportSession::write()}).
 */
final class SynchronousStreamTransport implements SessionAwareTransport, DiscardingTransport
{
    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<string>
     */
    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): \Amp\Future
    {
        return $this->exchange($config, $rawRequest, $cancellation, static fn (SynchronousStreamSession $session): string => $session->readResponse());
    }

    /**
     * @param iterable<string> $rawRequest
     * @return \Amp\Future<IcapResponse>
     */
    #[\Override]
    public function requestDiscardingBody(Config $config, iterable $rawRequest, int $maxResponseBytes, ?Cancellation $cancellation = null): \Amp\Future
    {
        return $this->exchange($config, $rawRequest, $cancellation, static fn (SynchronousStreamSession $session): IcapResponse => $session->readResponseDiscardingBody($maxResponseBytes));
    }

    #[\Override]
    public function openSession(Config $config, ?Cancellation $cancellation = null): SynchronousStreamSession
    {
        return SynchronousStreamSession::open($config, $cancellation);
    }

    /**
     * One request on a session of its own, and what the caller reads from it.
     *
     * @template T
     *
     * @param iterable<string>                               $rawRequest
     * @param \Closure(SynchronousStreamSession): T          $read
     *
     * @return \Amp\Future<T>
     */
    private function exchange(Config $config, iterable $rawRequest, ?Cancellation $cancellation, \Closure $read): \Amp\Future
    {
        $session = $this->openSession($config, $cancellation);
        try {
            $session->sendRequest($rawRequest);

            return \Amp\Future::complete($read($session));
        } finally {
            $session->close();
        }
    }
}

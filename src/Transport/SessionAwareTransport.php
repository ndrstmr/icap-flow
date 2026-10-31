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

/**
 * Capability of a transport that hands out a {@see TransportSession}: a connection that carries more than one
 * write/read round trip. {@see AsyncAmpTransport} and {@see SynchronousStreamTransport} implement it.
 *
 * A preview that the server answers with `100 Continue` is continued on the connection it began on (RFC 3507 §4.5),
 * so {@see \Ndrstmr\Icap\IcapClient} refuses `scanFileWithPreview*()` with a transport that does not implement this
 * interface (a `\LogicException`, before a file is opened or a connection made). Everything else works with
 * {@see TransportInterface} alone.
 */
interface SessionAwareTransport extends TransportInterface
{
    /**
     * Open a session against the host described by $config. The
     * caller MUST eventually call {@see TransportSession::release()}
     * or {@see TransportSession::close()} on the returned session.
     */
    public function openSession(Config $config, ?Cancellation $cancellation = null): TransportSession;
}

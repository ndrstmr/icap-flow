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
 * Optional capability surface for transports that can hand out a
 * {@see TransportSession}. The default async transport
 * ({@see AsyncAmpTransport}) implements both this and
 * {@see TransportInterface}; the synchronous transport intentionally
 * does not — sync is for CLI usage where strict RFC 3507 §4.5
 * preview-continue isn't worth the added complexity.
 *
 * Callers (typically {@see \Ndrstmr\Icap\IcapClient}) check for this
 * interface via `instanceof` before opting into multi-round flows.
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

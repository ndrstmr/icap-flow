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

/**
 * Capability of a transport that blocks the PHP process while waiting on the network.
 * {@see SynchronousStreamTransport} implements it.
 *
 * Because the process is already blocked by the transport, {@see \Ndrstmr\Icap\IcapClient} reads local files
 * in-process via amphp/file's blocking driver instead of starting worker processes. Transports without this
 * interface keep reading through worker processes so the event loop keeps running.
 */
interface BlockingTransport extends TransportInterface
{
}

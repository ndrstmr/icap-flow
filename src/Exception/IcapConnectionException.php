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

namespace Ndrstmr\Icap\Exception;

use RuntimeException;

/**
 * The connection to the ICAP server cannot be made or fails while in use: refused, connect timeout, TLS handshake,
 * or a socket that breaks during the request or the answer. The Amp exception is the previous exception.
 */
final class IcapConnectionException extends RuntimeException implements IcapExceptionInterface
{
}

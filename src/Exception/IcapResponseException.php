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
 * Thrown when an invalid or unexpected ICAP response is encountered.
 *
 * @deprecated since 2.0 — use the more specific IcapProtocolException,
 *   IcapMalformedResponseException, IcapClientException (4xx) or
 *   IcapServerException (5xx) introduced by M0.1. This class will be
 *   removed in a future minor release once all internal throw sites are
 *   migrated (M2).
 */
class IcapResponseException extends RuntimeException implements IcapExceptionInterface
{
}

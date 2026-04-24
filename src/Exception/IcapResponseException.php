<?php

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

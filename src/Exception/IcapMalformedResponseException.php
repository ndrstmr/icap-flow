<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * The raw bytes received from the ICAP server could not be parsed into a
 * valid IcapResponse (invalid status line, oversized headers, truncated
 * body, etc.).
 */
final class IcapMalformedResponseException extends IcapProtocolException
{
}

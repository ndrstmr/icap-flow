<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * Signals a violation of the ICAP wire protocol (RFC 3507) — malformed
 * messages, unexpected sequencing (e.g. 100 Continue outside preview),
 * or missing mandatory headers.
 */
class IcapProtocolException extends \RuntimeException implements IcapExceptionInterface
{
}

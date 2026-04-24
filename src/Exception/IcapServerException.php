<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * The ICAP server returned a 5xx response (service unavailable, internal
 * error, overloaded). Callers may choose to retry with backoff.
 */
final class IcapServerException extends \RuntimeException implements IcapExceptionInterface
{
}

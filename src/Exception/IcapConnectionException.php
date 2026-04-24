<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

use RuntimeException;

/**
 * Thrown when a connection to the ICAP server cannot be established.
 */
final class IcapConnectionException extends RuntimeException implements IcapExceptionInterface
{
}

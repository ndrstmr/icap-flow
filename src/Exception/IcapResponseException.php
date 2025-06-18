<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

use RuntimeException;

/**
 * Thrown when an invalid or unexpected ICAP response is encountered.
 */
class IcapResponseException extends RuntimeException
{
}

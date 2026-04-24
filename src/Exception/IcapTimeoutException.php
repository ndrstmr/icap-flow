<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * A connect, read, or write timeout elapsed before the ICAP transaction
 * completed.
 */
final class IcapTimeoutException extends \RuntimeException implements IcapExceptionInterface
{
}

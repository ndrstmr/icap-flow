<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * The ICAP server returned a 4xx response indicating the request was
 * rejected as malformed, unauthorised, or otherwise the client's fault.
 */
final class IcapClientException extends \RuntimeException implements IcapExceptionInterface
{
}

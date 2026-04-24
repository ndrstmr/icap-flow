<?php

declare(strict_types=1);

namespace Ndrstmr\Icap\Exception;

/**
 * Marker interface implemented by every exception thrown from the ICAP client.
 *
 * Catching this interface is the supported way to handle any failure that
 * originates in the library without accidentally catching unrelated
 * RuntimeExceptions from user code.
 */
interface IcapExceptionInterface extends \Throwable
{
}

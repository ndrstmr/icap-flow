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

namespace Ndrstmr\Icap\Tests;

use Amp\Cancellation;
use Amp\Future;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\DiscardingTransport;

/**
 * Not one transport: the session of the async transport carries the preview and the rest of the file on one socket
 * (RFC 3507 §4.5), a transport without sessions sends the file again in a request of its own.
 */
final class OneRequestTransport implements DiscardingTransport
{
    public function __construct(private readonly AsyncAmpTransport $inner = new AsyncAmpTransport())
    {
    }

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        return $this->inner->request($config, $rawRequest, $cancellation);
    }

    #[\Override]
    public function requestDiscardingBody(Config $config, iterable $rawRequest, int $maxResponseBytes, ?Cancellation $cancellation = null): Future
    {
        return $this->inner->requestDiscardingBody($config, $rawRequest, $maxResponseBytes, $cancellation);
    }
}

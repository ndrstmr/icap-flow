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
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\TransportInterface;

/**
 * Transport double for source tests: drains every request the way a real
 * transport writes it, keeps what went over the wire and answers from a
 * script, one reply per request. A request that breaks off while it is
 * written keeps the bytes that were written before it did.
 */
final class ScriptedTransport implements TransportInterface
{
    /** @var list<string> */
    public array $requests = [];

    /**
     * @param list<string> $replies one reply per request, in order; 'fail' fails the request,
     *                              and a request beyond the script is answered with 204
     */
    public function __construct(private readonly array $replies = [])
    {
    }

    #[\Override]
    public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
    {
        $wire = '';
        try {
            foreach ($rawRequest as $chunk) {
                $wire .= $chunk;
            }
        } finally {
            $this->requests[] = $wire;
        }

        $reply = $this->replies[count($this->requests) - 1]
            ?? "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

        return $reply === 'fail'
            ? Future::error(new IcapConnectionException('transport failed'))
            : Future::complete($reply);
    }
}

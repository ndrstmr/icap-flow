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

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;

/**
 * Formats ICAP requests into a sequence of raw byte chunks ready for
 * transport.
 */
interface RequestFormatterInterface
{
    /**
     * Render the ICAP request into a sequence of raw byte chunks.
     *
     * Returning an iterable (instead of one large string) allows transports
     * to stream large encapsulated HTTP bodies to the socket without
     * buffering the whole request in memory.
     *
     * @return iterable<string>
     */
    public function format(IcapRequest $request): iterable;
}

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

namespace Ndrstmr\Icap\Transport;

use Ndrstmr\Icap\Config;

/**
 * Contract for ICAP transport implementations.
 *
 * The raw request is supplied as an iterable of byte chunks so large
 * encapsulated HTTP bodies are streamed onto the socket without being
 * concatenated in memory.
 */
interface TransportInterface
{
    /**
     * @param iterable<string> $rawRequest Request bytes, in transport order
     *
     * @return \Amp\Future<string>
     */
    public function request(Config $config, iterable $rawRequest): \Amp\Future;
}

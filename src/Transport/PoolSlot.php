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

/**
 * One connection the pool answers for, from the moment room is reserved for it until its socket is closed.
 * It says what the connection counts against (the endpoint), where it may be given back to (the bucket: the
 * endpoint and the TLS context it was opened for) and whether it sits idle in the pool.
 *
 * A slot lives in exactly one of the pool's registries, so it is counted once and given up once.
 *
 * @internal
 */
final class PoolSlot
{
    public bool $idle = false;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $bucket,
    ) {
    }
}

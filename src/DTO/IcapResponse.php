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

namespace Ndrstmr\Icap\DTO;

/**
 * Immutable DTO representing an ICAP response.
 */
final readonly class IcapResponse
{
    /** @var array<string, string[]> */
    public array $headers;

    /**
     * @param int $statusCode HTTP status code, e.g. 200, 204, 500
     * @param array<string, string[]> $headers Response headers
     * @param string $body Response body content
     */
    public function __construct(
        public int $statusCode,
        array $headers = [],
        public string $body = ''
    ) {
        $this->headers = array_map(fn ($v) => (array) $v, $headers);
    }

    /**
     * @param string|string[] $value
     */
    public function withHeader(string $name, string|array $value): self
    {
        $headers = $this->headers;
        $headers[$name] = (array) $value;

        return new self(
            $this->statusCode,
            $headers,
            $this->body,
        );
    }
}

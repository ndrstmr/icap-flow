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

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;

/**
 * Parses raw ICAP server responses into {@link IcapResponse} value
 * objects. A string is one complete response: {@see ResponseDecoder} reads it
 * by its `Encapsulated` layout (RFC 3507 §4.4) with the same rules the
 * transports frame it by, and decodes the chunked body. A response that does
 * not follow them, is cut off, or has bytes behind its end is refused.
 */
final class ResponseParser implements ResponseParserInterface
{
    private const int DEFAULT_MAX_HEADER_COUNT = 100;
    private const int DEFAULT_MAX_HEADER_LINE = 8192;

    public function __construct(
        private readonly int $maxHeaderCount = self::DEFAULT_MAX_HEADER_COUNT,
        private readonly int $maxHeaderLineLength = self::DEFAULT_MAX_HEADER_LINE,
    ) {
        if ($maxHeaderCount < 1 || $maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('Parser limits must be >= 1');
        }
    }

    #[\Override]
    public function parse(string $rawResponse): IcapResponse
    {
        // All the bytes are here, so the response cannot be larger than they are.
        $decoder = new ResponseDecoder(
            maxResponseSize: max(1, strlen($rawResponse)),
            maxHeaderCount: $this->maxHeaderCount,
            maxHeaderLineLength: $this->maxHeaderLineLength,
        );
        $decoder->feed($rawResponse);

        if (!$decoder->isComplete()) {
            throw new IcapMalformedResponseException('Invalid ICAP response: it ends before the response is complete.');
        }
        if ($decoder->remainder() !== '') {
            throw new IcapMalformedResponseException('Invalid ICAP response: bytes follow the end of the response.');
        }

        return $decoder->response();
    }
}

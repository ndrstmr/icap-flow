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

/**
 * HTTP/1.1 chunked-transfer encoder used by both the {@see
 * RequestFormatter} (for the encapsulated body inside an ICAP request)
 * and by the strict RFC 3507 §4.5 preview-continue path (where the
 * client sends the rest of the body on the same socket after the
 * server's `100 Continue` response).
 *
 * Strings are emitted as a single chunk; resources are read in
 * {@see self::CHUNK_SIZE} blocks so a multi-gigabyte body never needs
 * to reside in memory.
 */
final class ChunkedBodyEncoder
{
    public const int CHUNK_SIZE = 8192;

    /**
     * @param  string|resource  $body
     * @return iterable<string>
     */
    public function encode(mixed $body, bool $previewIsComplete = false): iterable
    {
        $terminator = $previewIsComplete ? "0; ieof\r\n\r\n" : "0\r\n\r\n";

        if (is_string($body)) {
            if ($body !== '') {
                yield dechex(strlen($body)) . "\r\n" . $body . "\r\n";
            }
            yield $terminator;
            return;
        }

        if (!is_resource($body)) {
            throw new \InvalidArgumentException(
                'Encapsulated HTTP body must be a string or a readable stream resource.',
            );
        }

        rewind($body);
        while (!feof($body)) {
            $chunk = fread($body, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            yield dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
        yield $terminator;
    }
}

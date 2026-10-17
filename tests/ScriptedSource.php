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

/**
 * A readable file as a stream wrapper, for sources that misbehave in ways a
 * plain file cannot: a size the handle does not report, metadata that cannot
 * be had or throws, a read that fails, a read that makes no progress before
 * the end, a stream that cannot seek, or more or fewer bytes than the handle
 * announced. It counts opens and closes, so a test can see what happened to
 * the handle itself.
 *
 *     $path = ScriptedSource::add('case', 'abcdef', failAfter: 3);
 *     try { ... } finally { ScriptedSource::reset(); }
 */
final class ScriptedSource
{
    private const string SCHEME = 'icapsrc';

    /** @var array<string, array{data: string, size: int|null, pathSize: int|null, statFails: bool, statThrows: bool, failAfter: int|null, stallAfter: int|null, seekable: bool}> */
    private static array $sources = [];

    public static int $opened = 0;

    public static int $closed = 0;

    public mixed $context = null;

    private string $name = '';

    private int $position = 0;

    /**
     * @param int|null $size       size `fstat()` reports on the open handle (default: the data length)
     * @param int|null $pathSize   size a path lookup (`filesize()`) reports (default: `$size`)
     * @param bool     $statFails  `fstat()` finds no metadata
     * @param bool     $statThrows `fstat()` throws instead (the stack trace of that exception holds the handle)
     * @param int|null $failAfter  reads fail (`false`) once this many bytes were delivered
     * @param int|null $stallAfter reads return '' without reaching the end once this many bytes were delivered
     * @param bool     $seekable   `rewind()` / `fseek()` succeed
     *
     * @return string path to pass to the client
     */
    public static function add(
        string $name,
        string $data,
        ?int $size = null,
        ?int $pathSize = null,
        bool $statFails = false,
        bool $statThrows = false,
        ?int $failAfter = null,
        ?int $stallAfter = null,
        bool $seekable = true,
    ): string {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$sources[$name] = compact('data', 'size', 'pathSize', 'statFails', 'statThrows', 'failAfter', 'stallAfter', 'seekable');

        return self::SCHEME . '://' . $name;
    }

    public static function reset(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
        self::$sources = [];
        self::$opened = 0;
        self::$closed = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $name = substr($path, strlen(self::SCHEME . '://'));
        if (!isset(self::$sources[$name])) {
            return false;
        }
        $this->name = $name;
        self::$opened++;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $source = self::$sources[$this->name];
        $limit = strlen($source['data']);
        if ($source['failAfter'] !== null && $this->position >= $source['failAfter']) {
            return false;
        }
        if ($source['stallAfter'] !== null && $this->position >= $source['stallAfter']) {
            return '';
        }
        foreach (['failAfter', 'stallAfter'] as $cut) {
            if ($source[$cut] !== null) {
                $limit = min($limit, $source[$cut]);
            }
        }
        $block = substr($source['data'], $this->position, max(0, min($count, $limit - $this->position)));
        $this->position += strlen($block);

        return $block;
    }

    public function stream_eof(): bool
    {
        $source = self::$sources[$this->name];
        if ($source['failAfter'] !== null || $source['stallAfter'] !== null) {
            return false;
        }

        return $this->position >= strlen($source['data']);
    }

    /**
     * @return array<string, int>|false
     */
    public function stream_stat(): array|false
    {
        $source = self::$sources[$this->name];
        if ($source['statThrows']) {
            throw new \RuntimeException('The scripted source cannot be inspected.');
        }

        return $source['statFails'] ? false : ['size' => $source['size'] ?? strlen($source['data'])];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $source = self::$sources[substr($path, strlen(self::SCHEME . '://'))] ?? null;
        if ($source === null) {
            return false;
        }

        return ['size' => $source['pathSize'] ?? $source['size'] ?? strlen($source['data'])];
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $source = self::$sources[$this->name];
        if (!$source['seekable']) {
            return false;
        }
        $base = match ($whence) {
            SEEK_CUR => $this->position,
            SEEK_END => strlen($source['data']),
            default  => 0,
        };
        $target = $base + $offset;
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_close(): void
    {
        self::$closed++;
    }
}

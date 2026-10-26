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

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\Whence;
use Mockery;
use Ndrstmr\Icap\FileCalls;

use function Amp\delay;

/**
 * A readable file for sources that misbehave in ways a plain file cannot: a size that is not the length of what it
 * delivers, a size that cannot be had or whose lookup throws, a read that fails, a read that makes no progress before
 * the end, a file that cannot be positioned any more, or more or fewer bytes than its size. It is served two ways:
 * as a stream wrapper for `fopen()` (a stream of the caller) and, once added, through the file driver of the client,
 * in memory. It counts lookups, opens and closes, so a test can see what happened to the handle
 * itself.
 *
 *     $path = ScriptedSource::add('case', 'abcdef', failAfter: 3);
 *     try { ... } finally { ScriptedSource::reset(); }
 */
final class ScriptedSource
{
    private const string SCHEME = 'icapsrc';

    /** @var array<string, array{data: string, size: int|null, statFails: bool, statThrows: bool, failAfter: int|null, stallAfter: int|null, seekable: bool, openDelay: float, onRead: \Closure|null, onClose: \Closure|null}> */
    private static array $sources = [];

    private static bool $driverInstalled = false;

    public static int $lookups = 0;

    public static int $opened = 0;

    public static int $closed = 0;

    public static int $reads = 0;

    public mixed $context = null;

    private string $name = '';

    private int $position = 0;

    /**
     * @param int|null $size       size the open file reports when seeked to its end (default: the data length)
     * @param bool     $statFails  that seek fails: the size cannot be had
     * @param bool     $statThrows that seek throws instead (the stack trace of that exception holds the handle)
     * @param int|null $failAfter  reads fail once this many bytes were delivered
     * @param int|null $stallAfter reads return '' without reaching the end once this many bytes were delivered
     * @param bool     $seekable   the file can be positioned once it was read
     * @param float    $openDelay  seconds the driver takes to open the file (the event loop runs meanwhile)
     * @param \Closure|null $onRead  runs when a read begins, and may hold it, cancel, or throw
     * @param \Closure|null $onClose runs when the file is closed, and may throw
     *
     * @return string path to pass to the client
     */
    public static function add(
        string $name,
        string $data,
        ?int $size = null,
        bool $statFails = false,
        bool $statThrows = false,
        ?int $failAfter = null,
        ?int $stallAfter = null,
        bool $seekable = true,
        float $openDelay = 0.0,
        ?\Closure $onRead = null,
        ?\Closure $onClose = null,
    ): string {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$sources[$name] = compact('data', 'size', 'statFails', 'statThrows', 'failAfter', 'stallAfter', 'seekable', 'openDelay', 'onRead', 'onClose');
        if (!self::$driverInstalled) {
            self::installDriver();
        }

        return self::SCHEME . '://' . $name;
    }

    /**
     * The file driver of the client serves the scripted paths from memory, as regular files, and leaves every other path
     * to a real one.
     */
    private static function installDriver(): void
    {
        /** @var FilesystemDriver&\Mockery\MockInterface $driver */
        $driver = Mockery::mock(FilesystemDriver::class);
        /** @var \Mockery\Expectation $open */
        $open = $driver->shouldReceive('openFile');
        $open->andReturnUsing(static fn (string $path, string $mode): File => self::serves($path)
            ? self::open($path)
            : FileWorkers::driver()->openFile($path, $mode));

        /** @var \Mockery\Expectation $status */
        $status = $driver->shouldReceive('getStatus');
        $status->andReturnUsing(static function (string $path): ?array {
            if (!self::serves($path)) {
                return FileWorkers::driver()->getStatus($path);
            }
            self::$lookups++;

            return ['mode' => 0o100644, 'size' => strlen(self::$sources[self::name($path)]['data'])];
        });

        FileCalls::use($driver);
        self::$driverInstalled = true;
    }

    /**
     * Whether the path names a scripted source. The client hands the driver a relative path as an absolute one, so
     * the scheme can follow a directory.
     */
    public static function serves(string $path): bool
    {
        return isset(self::$sources[self::name($path)]);
    }

    /**
     * The scripted file as the file driver hands it out: only what the client uses of it (read, seek, close), in
     * memory, and counted.
     */
    public static function open(string $path): File
    {
        $source = self::$sources[self::name($path)];
        delay($source['openDelay']);
        self::$opened++;

        $position = 0;
        $consumed = 0;
        $closed = false;

        /** @var File&\Mockery\MockInterface $file */
        $file = Mockery::mock(File::class);

        /** @var \Mockery\Expectation $read */
        $read = $file->shouldReceive('read');
        $read->andReturnUsing(static function (?Cancellation $cancellation = null, int $length = File::DEFAULT_READ_LENGTH) use (&$position, &$consumed, $source): ?string {
            self::$reads++;
            ($source['onRead'])?->__invoke();
            if ($source['failAfter'] !== null && $position >= $source['failAfter']) {
                throw new StreamException('Failed reading from file');
            }
            if ($source['stallAfter'] !== null && $position >= $source['stallAfter']) {
                return '';
            }

            $limit = min(strlen($source['data']), $source['failAfter'] ?? PHP_INT_MAX, $source['stallAfter'] ?? PHP_INT_MAX);
            $block = substr($source['data'], $position, max(0, min($length, $limit - $position)));
            $position += strlen($block);
            $consumed += strlen($block);

            return $block === '' ? null : $block;
        });

        /** @var \Mockery\Expectation $seek */
        $seek = $file->shouldReceive('seek');
        $seek->andReturnUsing(static function (int $offset, Whence $whence = Whence::Start) use (&$position, &$consumed, $source): int {
            if ($whence === Whence::End) {
                if ($source['statThrows']) {
                    throw new \RuntimeException('The scripted source cannot be inspected.');
                }
                if ($source['statFails']) {
                    throw new StreamException('Could not seek in file');
                }

                // The size the file reports is what the client takes for its size.
                return $position = ($source['size'] ?? strlen($source['data'])) + $offset;
            }
            if (!$source['seekable'] && $consumed > 0) {
                throw new StreamException('Could not seek in file');
            }

            return $position = ($whence === Whence::Current ? $position : 0) + $offset;
        });

        /** @var \Mockery\Expectation $close */
        $close = $file->shouldReceive('close');
        $close->andReturnUsing(static function () use (&$closed, $source): void {
            if (!$closed) {
                $closed = true;
                self::$closed++;
                ($source['onClose'])?->__invoke();
            }
        });

        return $file;
    }

    private static function name(string $path): string
    {
        $at = strpos($path, self::SCHEME . '://');

        return $at === false ? '' : substr($path, $at + strlen(self::SCHEME . '://'));
    }

    public static function reset(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
        if (self::$driverInstalled) {
            FileWorkers::useDefaultDriver();
            self::$driverInstalled = false;
        }
        self::$sources = [];
        self::$lookups = 0;
        self::$opened = 0;
        self::$closed = 0;
        self::$reads = 0;
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

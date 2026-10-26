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

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Amp\Future;
use Amp\NullCancellation;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerException;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Suspension;

use function Amp\async;

/**
 * The calls of the client to its file driver: worker processes of amphp/parallel, one set per event loop, whatever
 * driver the application configured for its own files. Only these are known to give a regular file a size and an end;
 * a blocking driver would hold the event loop with the first open.
 *
 * A worker that hangs in the operating system does not answer, and no cancellation reaches it. Each call therefore
 * runs in a fiber of its own and the operation only stops waiting for it; the call stays in the worker. It holds one
 * of {@see MAX_PENDING} places until the driver has returned, not until the wait is over, and so does the close of a
 * file. A caller that finds no place waits for one as long as its operation lives, and sends nothing to the driver,
 * and a call whose operation ended while it waited or was scheduled does not start.
 *
 * The type of a path is looked up by a worker of its own. The workers of the files are leased to them for as long as
 * they are open, so a lookup that waited for one would wait for the end of scans that wait for the places it holds.
 *
 * @internal
 */
final class FileCalls
{
    /** Driver calls that may be on their way at the same time, those nobody waits for any more included. */
    public const int MAX_PENDING = 64;

    /** The bits of the mode of a stat result that give the type of the file, and the type of a regular file. */
    private const int TYPE_MASK = 0o170000;

    private const int REGULAR = 0o100000;

    /** @var \WeakMap<Driver, self>|null */
    private static ?\WeakMap $perLoop = null;

    private static ?self $replacement = null;

    private int $free = self::MAX_PENDING;

    private int $turns = 0;

    /** @var array<int, Suspension<null>> callers waiting for a place, in the order they came */
    private array $waiting = [];

    private function __construct(
        private readonly FilesystemDriver $files,
        private readonly FilesystemDriver $paths,
    ) {
    }

    public static function current(): self
    {
        if (self::$replacement !== null) {
            return self::$replacement;
        }
        self::$perLoop ??= new \WeakMap();

        return self::$perLoop[EventLoop::getDriver()] ??= new self(
            new ParallelFilesystemDriver(),
            new ParallelFilesystemDriver(new ContextWorkerPool(1)),
        );
    }

    /**
     * For tests: serves the calls from another driver with places of its own; null puts the worker processes back.
     */
    public static function use(?FilesystemDriver $driver): void
    {
        self::$replacement = $driver === null ? null : new self($driver, $driver);
    }

    /**
     * Opens a regular file. Its type is looked up first, so that a FIFO is not opened and waited for, and a device or a
     * directory is not read as if it were a file; a path that cannot be looked up is none (a stream wrapper, a path
     * that does not exist).
     */
    public function open(string $path, Cancellation $cancellation): File
    {
        $opening = $this->begin(function () use ($path, $cancellation): File {
            $mode = $this->paths->getStatus($path)['mode'] ?? null;
            if (!is_int($mode) || ($mode & self::TYPE_MASK) !== self::REGULAR) {
                throw new FilesystemException('Not a regular file: ' . $path);
            }
            // The lookup may have outlived the operation.
            $cancellation->throwIfRequested();

            return $this->files->openFile($path, 'r');
        }, $cancellation);

        try {
            return $this->await($opening, $cancellation, 'Unable to open file: ' . $path);
        } catch (CancelledException $e) {
            // The worker may still deliver the file: it is closed, not used.
            $opening->map($this->detach(...))->ignore();
            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    public function run(Cancellation $cancellation, \Closure $call, string $failure): mixed
    {
        return $this->await($this->begin($call, $cancellation), $cancellation, $failure);
    }

    /**
     * Closes a file whose scan is done. The close is begun in any case, waited for as long as the operation lives, and
     * a close that fails is reported.
     */
    public function close(File $file, Cancellation $cancellation): void
    {
        $this->await($this->closing($file), $cancellation, 'Unable to close the file.');
    }

    /**
     * Closes a file nobody uses any more because the scan failed or was given up. Nobody waits for the close, and a
     * failure of it has nobody to tell.
     */
    public function detach(File $file): void
    {
        $this->closing($file)->ignore();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return Future<T>
     */
    private function begin(\Closure $call, Cancellation $cancellation): Future
    {
        $this->take($cancellation);

        /** @var Future<T> $future */
        $future = async(function () use ($call, $cancellation): mixed {
            try {
                // The call starts a loop turn after the place came, and the operation may have ended by then.
                $cancellation->throwIfRequested();

                return $call();
            } finally {
                $this->give();
            }
        });

        return $future;
    }

    /**
     * @return Future<null>
     */
    private function closing(File $file): Future
    {
        /** @var Future<null> $future */
        $future = async(function () use ($file): void {
            $this->take(new NullCancellation());
            try {
                $file->close();
            } finally {
                $this->give();
            }
        });

        return $future;
    }

    /**
     * Waits for the call as long as the operation lives. What the file driver reports as a failure of the file is an
     * {@see IcapStorageException}, unless the operation is over by then; anything else passes as it is.
     *
     * @template T
     *
     * @param Future<T> $call
     *
     * @return T
     */
    private function await(Future $call, Cancellation $cancellation, string $failure): mixed
    {
        try {
            return $call->await($cancellation);
        } catch (FilesystemException|StreamException|WorkerException|ContextException $e) {
            // The end of the operation outranks the failure that came in with it.
            $cancellation->throwIfRequested();

            throw new IcapStorageException($failure, 0, $e);
        }
    }

    private function take(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        if ($this->free > 0) {
            --$this->free;

            return;
        }

        $suspension = EventLoop::getSuspension();
        $turn = $this->turns++;
        $this->waiting[$turn] = $suspension;
        $watch = $cancellation->subscribe(function (CancelledException $e) use ($turn, $suspension): void {
            // Either the place or the end of the operation reaches the caller first, never both.
            if (isset($this->waiting[$turn])) {
                unset($this->waiting[$turn]);
                $suspension->throw($e);
            }
        });

        try {
            $suspension->suspend();
        } finally {
            $cancellation->unsubscribe($watch);
        }
    }

    private function give(): void
    {
        $turn = array_key_first($this->waiting);
        if ($turn === null) {
            ++$this->free;

            return;
        }

        $suspension = $this->waiting[$turn];
        unset($this->waiting[$turn]);
        $suspension->resume();
    }
}

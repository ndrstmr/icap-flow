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

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\File\File;
use Amp\File\Whence;
use Ndrstmr\Icap\Exception\IcapStorageException;

/**
 * A local file that the client opened once for one operation and reads through {@see FileCalls}, so that the event
 * loop keeps running while the file is read. The size is the end of the open file, and reads and the close go to that
 * same file; the path is not looked at again. Only a regular file is opened, and its size is what the
 * announced length of the body is checked against.
 *
 * The cancellation (the end of the operation) ends every wait for the driver. A scan ends with {@see close()} when it
 * went through, and with {@see discard()} otherwise.
 *
 * @internal
 */
final class LocalFile
{
    /** The most one read asks of the driver, and so the buffer a file needs per operation. */
    public const int BLOCK_SIZE = 65536;

    /** Whether the last read confirmed the end of the file. */
    private bool $atEnd = false;

    private function __construct(
        private ?File $file,
        private readonly FileCalls $calls,
        private readonly Cancellation $cancellation,
        public readonly int $size,
    ) {
    }

    /**
     * @param string $path a path of the local file system; a relative one is taken from the working directory now
     *
     * @throws IcapStorageException When the file cannot be opened or measured
     * @throws CancelledException   When the cancellation ends the wait for the driver
     */
    public static function open(string $path, Cancellation $cancellation): self
    {
        // The worker has the working directory it started in, which is not necessarily this one.
        if (preg_match('~^(?:[\\\\/]|[A-Za-z]:[\\\\/])~', $path) !== 1) {
            $directory = getcwd();
            if ($directory === false) {
                throw new IcapStorageException('Unable to resolve the relative path of file: ' . $path);
            }
            $path = $directory . DIRECTORY_SEPARATOR . $path;
        }

        $calls = FileCalls::current();
        $file = $calls->open($path, $cancellation);

        try {
            $size = $calls->run($cancellation, static function () use ($file): int {
                $size = $file->seek(0, Whence::End);
                $file->seek(0);

                return $size;
            }, 'Unable to determine the size of file: ' . $path);
        } catch (\Throwable $e) {
            $calls->detach($file);
            throw $e;
        }

        return new self($file, $calls, $cancellation, $size);
    }

    /**
     * The next bytes of the file, at most {@see BLOCK_SIZE}, or null once the end of the file is confirmed. A driver
     * that returns nothing without having reached the end would make the caller wait for ever.
     *
     * @param int<1, max> $length
     *
     * @throws IcapStorageException When the file cannot be read
     * @throws CancelledException   When the cancellation ends the wait for the driver
     */
    public function read(int $length): ?string
    {
        $file = $this->file ?? throw new \LogicException('The file has been closed.');

        $bytes = $this->calls->run(
            $this->cancellation,
            static fn (): ?string => $file->read(null, min($length, self::BLOCK_SIZE)),
            'Unable to read the file to its end.',
        );
        if ($bytes === '') {
            throw new IcapStorageException('Unable to read the file to its end.');
        }
        $this->atEnd = $bytes === null;

        return $bytes;
    }

    /**
     * Fails unless the file was read to its end. A formatter or transport that took less of the request than the
     * file leaves the server to judge a body that is not the file, and its answer is no verdict on it.
     *
     * @throws IcapStorageException When no read has confirmed the end of the file
     */
    public function assertRead(): void
    {
        if (!$this->atEnd) {
            throw new IcapStorageException('The request did not read the file to its end, so no verdict is one on the whole file.');
        }
    }

    /**
     * Ends a scan that went through: closes the file, waits for the close as long as the operation lives and reports
     * a close that fails.
     *
     * @throws IcapStorageException When the file cannot be closed
     * @throws CancelledException   When the cancellation ends the wait for the driver
     */
    public function close(): void
    {
        $file = $this->file;
        $this->file = null;

        if ($file !== null) {
            $this->calls->close($file, $this->cancellation);
        }
    }

    /**
     * Ends a scan that failed or was given up. The close goes on without anyone waiting for it, and what it reports is
     * not wanted over the failure that ended the scan. Does nothing after {@see close()}.
     */
    public function discard(): void
    {
        $file = $this->file;
        $this->file = null;

        if ($file !== null) {
            $this->calls->detach($file);
        }
    }
}

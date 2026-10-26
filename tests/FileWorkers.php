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

use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\FilesystemDriver;
use Amp\Parallel\Worker\ContextWorkerPool;
use Ndrstmr\Icap\FileCalls;

use function Amp\delay;

/**
 * The worker processes of the file driver the client uses, for tests that have to see what the real driver does: stop
 * a worker so that a call to it hangs, and look at which files a worker still holds open.
 */
final class FileWorkers
{
    private static ?FilesystemDriver $default = null;

    private static ?ContextWorkerPool $own = null;

    private static ?FilesystemDriver $ownDriver = null;

    /** @var list<string> */
    private static array $files = [];

    /**
     * Whether the open files of another process can be listed here (`/proc` or `lsof`).
     */
    public static function canInspect(): bool
    {
        return is_dir('/proc/self/fd') || is_executable('/usr/sbin/lsof') || is_executable('/usr/bin/lsof');
    }

    /**
     * The worker processes of this one (they carry the process title `amp-process`).
     *
     * @return list<int>
     */
    public static function pids(): array
    {
        $rows = [];
        foreach (explode("\n", trim((string) shell_exec('ps -axo pid=,ppid=,command='))) as $row) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $row, $m) === 1) {
                $rows[(int) $m[1]] = [(int) $m[2], trim($m[3])];
            }
        }

        // Everything that descends from this process, the shell that starts a worker included.
        $family = [(int) getmypid()];
        do {
            $grown = false;
            foreach ($rows as $pid => [$parent]) {
                if (in_array($parent, $family, true) && !in_array($pid, $family, true)) {
                    $family[] = $pid;
                    $grown = true;
                }
            }
        } while ($grown);

        $workers = [];
        foreach ($family as $pid) {
            if (($rows[$pid][1] ?? '') === 'amp-process') {
                $workers[] = $pid;
            }
        }

        return $workers;
    }

    /**
     * The files the workers hold open.
     *
     * @return list<string>
     */
    public static function openFiles(): array
    {
        $names = [];
        foreach (self::pids() as $pid) {
            if (is_dir("/proc/$pid/fd")) {
                foreach (glob("/proc/$pid/fd/*") ?: [] as $fd) {
                    $names[] = (string) @readlink($fd);
                }
            } else {
                foreach (explode("\n", (string) shell_exec("lsof -n -P -Fn -p $pid 2>/dev/null")) as $line) {
                    if (str_starts_with($line, 'n')) {
                        $names[] = substr($line, 1);
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Whether a worker holds the file open.
     */
    public static function holds(string $path): bool
    {
        return in_array(realpath($path), self::openFiles(), true);
    }

    /**
     * A file driver with one worker of its own, started, that a test can stop: a call to a stopped worker does not
     * return, like a call into an operating system that hangs.
     *
     * @return int the process id of the worker
     */
    public static function own(): int
    {
        self::useDefaultDriver();
        $before = self::pids();
        self::$own = new ContextWorkerPool(1);
        self::$ownDriver = new ParallelFilesystemDriver(self::$own);
        FileCalls::use(self::$ownDriver);

        self::$ownDriver->openFile(self::file(''), 'r')->close();

        return array_values(array_diff(self::pids(), $before))[0];
    }

    /**
     * The driver of {@see own()}, to put a counter in front of it.
     */
    public static function ownDriver(): FilesystemDriver
    {
        return self::$ownDriver ?? throw new \LogicException('No driver of its own was started.');
    }

    public static function stop(int $pid): void
    {
        shell_exec("kill -STOP $pid");
    }

    public static function resume(int $pid): void
    {
        shell_exec("kill -CONT $pid");
    }

    /**
     * A real driver for the paths that a test does not script.
     */
    public static function driver(): FilesystemDriver
    {
        return self::$default ??= new ParallelFilesystemDriver();
    }

    /**
     * Puts the driver of the client back, and ends the worker of {@see own()} the orderly way.
     */
    public static function useDefaultDriver(): void
    {
        $own = self::$own;
        self::$own = null;
        self::$ownDriver = null;
        FileCalls::use(null);

        $own?->shutdown();
    }

    /**
     * A file with this content, removed by {@see removeFiles()}.
     */
    public static function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'icap-io');
        file_put_contents($path, $content);

        return self::$files[] = $path;
    }

    /**
     * A sparse file of this size: it costs nothing on the disk, and every byte of it still has to be read.
     *
     * @param int<0, max> $bytes
     */
    public static function sparseFile(int $bytes): string
    {
        $path = self::file('');
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('The file could not be created.');
        }
        ftruncate($handle, $bytes);
        fclose($handle);

        return $path;
    }

    public static function removeFiles(): void
    {
        foreach (self::$files as $path) {
            @unlink($path);
        }
        self::$files = [];
    }

    /**
     * Waits (the event loop keeps running) until the condition holds.
     *
     * @param \Closure(): bool $condition
     */
    public static function settles(\Closure $condition, float $seconds = 3.0): bool
    {
        $until = hrtime(true) + $seconds * 1e9;
        while (!$condition()) {
            if (hrtime(true) > $until) {
                return false;
            }
            delay(0.02);
        }

        return true;
    }
}

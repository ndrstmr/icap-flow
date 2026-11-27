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

use PHPUnit\Framework\Assert;

use function Amp\delay;

/**
 * Runs a bounded PHP child process while the event loop of the test process keeps serving (for example an
 * {@see IcapTestServer}). The child receives the autoloader path as `$argv[1]` and a JSON payload as `$argv[2]`, and
 * writes one JSON line back to stdout.
 */
final class ChildProcess
{
    /**
     * @param list<string>         $phpOptions
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public static function run(array $phpOptions, string $code, array $job, float $within = 10.0): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                ...$phpOptions,
                '-d', 'display_errors=stderr',
                '-r', $code,
                '--',
                dirname(__DIR__) . '/vendor/autoload.php',
                json_encode($job, JSON_THROW_ON_ERROR),
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $until = hrtime(true) + $within * 1e9;
        try {
            while (true) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (!proc_get_status($process)['running']) {
                    break;
                }
                if (hrtime(true) > $until) {
                    Assert::fail("The child did not end within {$within} s and was killed. stderr: " . substr($stderr, 0, 500));
                }
                delay(0.02);
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $line = trim($stdout);
        Assert::assertNotSame('', $line, 'The child wrote no result: ' . substr($stderr, 0, 500));
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

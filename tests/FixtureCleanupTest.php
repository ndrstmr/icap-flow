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

use Amp\CancelledException;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\OperationDeadline;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Pest\Expectation;
use PHPUnit\Framework\Assert;

/*
 * `AsyncTestCase::runAsyncTest()` waits until the event loop runs out of work, so a listener or a timer that a failed
 * test leaves in the loop holds up the next test for good. A child process runs a real test of this suite with a failure
 * in its way, and then the test of tests/Fixtures/test-after-a-failure.php.
 */

/**
 * @param list<string> $failing classes that fail to load
 *
 * @return array{finished: bool, exit: int, events: string, output: string}
 */
function childTestRun(string $file, string $test, array $failing, string $kind): array
{
    $base = tempnam(sys_get_temp_dir(), 'icap-child-test');
    if ($base === false) {
        throw new \RuntimeException('No temporary file for the child');
    }

    try {
        $process = proc_open(
            [
                PHP_BINARY, 'vendor/bin/pest', $file, 'tests/Fixtures/test-after-a-failure.php',
                '--no-coverage', '--ci', '--order-by=default', "--filter={$test}|runs to its end and finds nothing left",
                '--log-events-text', "{$base}.events",
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$base}.output", 'w'], 2 => ['file', "{$base}.output", 'a']],
            $pipes,
            dirname(__DIR__),
            // A worker of a parallel run passes on what makes this process one: the child is a run of its own.
            array_diff_key(getenv(), ['PARATEST' => true, 'PEST_PARALLEL' => true, 'TEST_TOKEN' => true, 'UNIQUE_TEST_TOKEN' => true]) + [
                'ICAP_FAILING_CLASSES' => implode(',', $failing),
                'ICAP_FAILURE_KIND'    => $kind,
            ],
        );
        Assert::assertIsResource($process);

        $status = proc_get_status($process);
        $pid = $status['pid'];
        $until = hrtime(true) + 20e9;
        try {
            while ($status['running'] && hrtime(true) < $until) {
                usleep(10_000);
                $status = proc_get_status($process);
            }

            return [
                'finished' => !$status['running'],
                'exit'     => $status['exitcode'],
                'events'   => (string) @file_get_contents("{$base}.events"),
                'output'   => (string) @file_get_contents("{$base}.output"),
            ];
        } finally {
            // The process group of the child: its workers end with it, stopped or not.
            posix_kill(-$pid, 9);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    } finally {
        foreach ([$base, "{$base}.events", "{$base}.output"] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}

it('reports the failure of a test, releases what the test holds and runs the next test', function () {
    $fileIo = 'tests/FileIoTest.php';
    $transport = 'tests/Transport/AsyncAmpTransportTest.php';
    $discarding = 'tests/DiscardingResponseTest.php';
    $stuck = 'closes the file when the deadline ends a scan that is stuck writing to its socket';
    $slowOpen = 'keeps the event loop running while the file to scan is opened';
    $stopped = 'ends at the operation timeout while the file driver does not answer';
    $cancelled = 'keeps the cancellation of the caller a cancellation while the file driver does not answer';

    // [test file, test, classes that fail to load, kind of failure, sockets and timers the test holds at the first of them]
    // A scan takes the failure as its result; the class that its assertion loads then makes the test report the failure.
    $cases = [
        [$fileIo, $stuck, [RequestFormatter::class], 'exception', 1, 0],
        [$fileIo, $stuck, [RequestFormatter::class], 'assertion', 1, 0],
        [$fileIo, $stuck, [OperationDeadline::class, IcapTimeoutException::class], 'exception', 1, 1],
        [$fileIo, $slowOpen, [ScriptedTransport::class], 'exception', 0, 1],
        [$fileIo, $slowOpen, [Config::class], 'exception', 0, 2],
        [$fileIo, $stopped, [Config::class, IcapTimeoutException::class], 'exception', 0, 1],
        [$fileIo, $cancelled, [Config::class, CancelledException::class], 'exception', 0, 1],
        // The first expect() of a test: what it has started is still running.
        ['tests/OperationDeadlineTest.php', 'takes a huge but finite limit', [Expectation::class], 'exception', 0, 1],
        ['tests/OperationDeadlineTest.php', 'forwards the cancellation of the caller', [Expectation::class], 'exception', 0, 1],
        // A pool holds nothing yet: it comes before the server.
        [$transport, 'ends a TLS handshake that the server never answers at the limit of its own', [AmpConnectionPool::class], 'exception', 0, 0],
        [$transport, 'reports a TLS handshake that fails as IcapConnectionException', [AmpConnectionPool::class], 'exception', 0, 0],
        [$discarding, 'refuses a response that is forged, cut or illegal at its end', [AmpConnectionPool::class], 'exception', 0, 0],
        [$discarding, 'ends the receipt of a large body with the cancellation of the caller', [AmpConnectionPool::class], 'exception', 0, 0],
        ['tests/CicapVerdictClientTest.php', 'does not give a socket back after an answer that is not a verdict', [AmpConnectionPool::class], 'exception', 0, 0],
    ];
    // A host without an IPv6 loopback skips this test.
    if (@stream_socket_server('tcp://[::1]:0') !== false) {
        $cases[] = [$transport, 'connects to an IPv6 literal host', [AmpConnectionPool::class], 'exception', 1, 0];
    }

    foreach ($cases as [$file, $test, $failing, $kind, $sockets, $timers]) {
        $run = childTestRun($file, $test, $failing, $kind);
        $event = $kind === 'assertion' ? 'Test Failed' : 'Test Errored';
        $name = (string) preg_replace('/\W/', '_', $test);

        expect($run['finished'])->toBeTrue("The child did not end: {$test}\n{$run['events']}\n{$run['output']}")
            ->and($run['events'])->toContain('Test Runner Execution Started (2 tests)')
            ->and($run['events'])->toMatch("/^{$event} \\(.*{$name}.*\\)\\n.*INJECTED_FAILURE sockets={$sockets} timers={$timers}\\b/m")
            ->and($run['events'])->toMatch('/^Test Passed \(.*runs_to_its_end_and_finds_nothing_left\)$/m')
            ->and($run['exit'])->not->toBe(0, $test)
            ->and(trim($run['events']))->toEndWith("PHPUnit Finished (Shell Exit Code: {$run['exit']})");
    }
});

it('finds nothing left of a test that passes after a real client connected', function () {
    $run = childTestRun('tests/FileIoTest.php', 'closes the file when the deadline ends a scan that is stuck writing to its socket', [], 'exception');

    expect($run['finished'])->toBeTrue("The child did not end\n{$run['events']}\n{$run['output']}")
        ->and($run['events'])->toContain('Test Runner Execution Started (2 tests)')
        ->and(substr_count($run['events'], 'Test Passed ('))->toBe(2, $run['events'])
        ->and($run['exit'])->toBe(0)
        ->and(trim($run['events']))->toEndWith('PHPUnit Finished (Shell Exit Code: 0)');
});

it('starts no child and deletes nothing without a temporary file', function () {
    $directory = sys_get_temp_dir() . '/icap-child-test-' . bin2hex(random_bytes(6));
    mkdir($directory);
    touch("{$directory}/foreign");
    // A process of its own in that directory, where tempnam() returns false: its temporary directory does not exist.
    // It reads this file for the helper; should the helper get as far as proc_open(), that starts nothing of the suite.
    $code = <<<'PHP'
        namespace Ndrstmr\Icap\Tests;

        function it(): void {}
        function proc_open(): mixed { echo 'STARTED'; return \proc_open([PHP_BINARY, '-v'], [], $pipes); }

        require "{$argv[1]}/vendor/autoload.php";
        require "{$argv[1]}/tests/FixtureCleanupTest.php";
        childTestRun('', '', [], '');
        PHP;
    $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', "sys_temp_dir={$directory}/missing", '-d', 'display_errors=stderr', '-r', $code, dirname(__DIR__)]));

    try {
        exec('cd ' . escapeshellarg($directory) . " && {$command} 2>&1", $output);
        $left = scandir($directory);
    } finally {
        @unlink("{$directory}/foreign");
        rmdir($directory);
    }

    expect($left)->toBe(['.', '..', 'foreign'])
        ->and(implode("\n", $output))->toContain('No temporary file for the child')
        ->and(implode("\n", $output))->not->toContain('STARTED');
});

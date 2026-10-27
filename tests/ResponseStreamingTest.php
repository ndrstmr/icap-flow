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

/*
 * AP-15 (M02) — a gigabyte over a real socket. The client runs in a child process of its own whose memory
 * limit is far below the size of the payload, so a client that holds the response (as a frame, as a body or as
 * both) is killed by the limit; the test plays the ICAP server on its own side, which answers every file with
 * an echo of it. The child is bounded by a time limit the test enforces and ends by itself when the test is
 * gone. ICAP_STREAM_MIB sets the large size (default 1024) for a machine that is too slow for a gigabyte.
 */

/**
 * The child reads one JSON argument: port, file, transport ('async' or 'sync'), parallel, budget and, for a scan
 * with preview, the size of the preview. It scans the file `parallel` times at once with the discarding scan and
 * writes one JSON line: the discarded bytes of each
 * scan, the peak of the memory PHP allocated, the resident size of the process and the seconds it took.
 */
const STREAMING_CHILD = <<<'PHP'
require $argv[1];
$job = json_decode($argv[2], true);
$parent = posix_getppid();
// A client whose test is gone ends: the parent of the process changes when it ends.
Revolt\EventLoop::unreference(Revolt\EventLoop::repeat(0.5, static function () use ($parent): void {
    if (posix_getppid() !== $parent) {
        exit(3);
    }
}));
$config = new Ndrstmr\Icap\Config('127.0.0.1', $job['port'], streamTimeout: 240.0, operationTimeout: 280.0);
$transport = $job['transport'] === 'sync' ? new Ndrstmr\Icap\Transport\SynchronousStreamTransport() : new Ndrstmr\Icap\Transport\AsyncAmpTransport();
$client = new Ndrstmr\Icap\IcapClient($config, $transport, new Ndrstmr\Icap\RequestFormatter(), new Ndrstmr\Icap\ResponseParser());
$started = hrtime(true);
try {
    $scans = [];
    for ($i = 0; $i < $job['parallel']; $i++) {
        $scans[] = isset($job['preview'])
            ? $client->scanFileWithPreviewDiscardingBody('/avscan', $job['file'], $job['budget'], $job['preview'])
            : $client->scanFileDiscardingBody('/avscan', $job['file'], $job['budget']);
    }
    // The results come back in the order the scans end; the figures are told in the order they were started.
    $results = Amp\Future\await($scans);
    ksort($results);
    $out = ['discarded' => array_map(static fn ($result) => $result->getOriginalResponse()->discardedBodyBytes, array_values($results))];
} catch (Throwable $e) {
    $out = ['error' => $e::class . ': ' . $e->getMessage()];
}
$out['peak'] = memory_get_peak_usage();
$out['rss'] = getrusage()['ru_maxrss'];
$out['elapsed'] = (hrtime(true) - $started) / 1e9;
echo json_encode($out), "\n";
PHP;

/**
 * Runs the client in a child with a memory limit, while this process serves it. The child is killed when it does not
 * end within $within seconds.
 *
 * @param array{port: int, file: string, transport: string, parallel: int, budget: int, preview?: int} $job
 *
 * @return array{discarded?: list<int|null>, error?: string, peak: int, rss: int, elapsed: float}
 */
function streamingRun(array $job, int $memoryMiB, float $within): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', "memory_limit={$memoryMiB}M", '-d', 'display_errors=stderr', '-r', STREAMING_CHILD, '--', dirname(__DIR__) . '/vendor/autoload.php', json_encode($job, JSON_THROW_ON_ERROR)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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
                Assert::fail("The client did not end within {$within} s and was killed.");
            }
            // The server of this process runs while it waits.
            delay(0.05);
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
    Assert::assertNotSame('', $line, 'The client wrote no result: ' . substr($stderr, 0, 500));
    $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    Assert::assertIsArray($result);

    /** @var array{discarded?: list<int|null>, error?: string, peak: int, rss: int, elapsed: float} $result */
    return $result;
}

it('scans a gigabyte at once in parallel with a memory that does not grow with the size of the response', function () {
    $large = max(8, (int) (getenv('ICAP_STREAM_MIB') ?: 1024)) * 1024 * 1024;
    $small = intdiv($large, 8);
    $parallel = 2;
    $server = IcapTestServer::start(static fn (array $request) => IcapTestServer::echo($request['bodyBytes']));
    $files = [];
    try {
        $peaks = [];
        foreach (['small' => $small, 'large' => $large] as $label => $size) {
            $files[$label] = FileWorkers::sparseFile(max(0, $size));
            // 64 MiB of memory for a response of up to a gigabyte, twice at the same time.
            $run = streamingRun(
                ['port' => $server->port, 'file' => $files[$label], 'transport' => 'async', 'parallel' => $parallel, 'budget' => 2 * $size],
                64,
                180.0,
            );

            expect($run['error'] ?? null)->toBeNull($label)
                ->and($run['discarded'] ?? null)->toBe(array_fill(0, $parallel, $size), $label);
            $peaks[$label] = $run['peak'];
        }

        // Eight times the response at the same parallelism: the memory of the client stays where it was.
        expect($peaks['large'] - $peaks['small'])->toBeLessThan(2 * 1024 * 1024)
            ->and($peaks['large'])->toBeLessThan(32 * 1024 * 1024);
    } finally {
        $server->stop();
    }
});

it('scans a gigabyte over the synchronous transport with a memory that does not grow with the response', function () {
    $size = max(8, (int) (getenv('ICAP_STREAM_MIB') ?: 1024)) * 1024 * 1024;
    $server = IcapTestServer::start(static fn (array $request) => IcapTestServer::echo($request['bodyBytes']));
    try {
        $path = FileWorkers::sparseFile(max(0, $size));
        // The payload is far above the memory limit of the child.
        $run = streamingRun(
            ['port' => $server->port, 'file' => $path, 'transport' => 'sync', 'parallel' => 1, 'budget' => 2 * $size],
            32,
            180.0,
        );

        expect($run['error'] ?? null)->toBeNull()
            ->and($run['discarded'] ?? null)->toBe([$size])
            ->and($run['peak'])->toBeLessThan(24 * 1024 * 1024);
    } finally {
        $server->stop();
    }
});

it('scans with a preview over the synchronous transport, which sends the file again in a request of its own', function () {
    $size = 12 * 1024 * 1024;
    $echo = static fn (array $request) => IcapTestServer::echo($size);
    $continue = static fn (array $request): array => [IcapTestServer::CONTINUE];

    // label => answer to the preview, answer to the file that is sent again
    $scenarios = [
        'a 200 at once'           => [$echo, null],
        '100 Continue, then 200'  => [$continue, $echo],
    ];
    foreach ($scenarios as $label => [$previewAnswer, $fileAnswer]) {
        $server = IcapTestServer::start(static fn (array $request) => $request['stage'] === 'preview' ? $previewAnswer($request) : ($fileAnswer ?? $echo)($request));
        try {
            $run = streamingRun(
                ['port' => $server->port, 'file' => FileWorkers::file(str_repeat('a', 5000)), 'transport' => 'sync', 'parallel' => 1, 'budget' => 4 * $size, 'preview' => 1024],
                32,
                60.0,
            );

            expect($run['error'] ?? null)->toBeNull($label)
                ->and($run['discarded'] ?? null)->toBe([$size], $label)
                ->and(array_column($server->requests, 'stage'))->toBe($fileAnswer === null ? ['preview'] : ['preview', 'single'], $label);
        } finally {
            $server->stop();
        }
    }
});

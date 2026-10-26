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
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Driver\StatusCachingFilesystemDriver;
use Amp\File\FilesystemDriver;
use Amp\Future;
use Amp\NullCancellation;
use Amp\Socket;
use Mockery;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\HttpResponse;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\FileCalls;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\LocalFile;
use Ndrstmr\Icap\OperationDeadline;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use PHPUnit\Framework\Assert;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\File\createDefaultDriver;
use function Amp\File\filesystem;
use function Amp\Future\await;

/*
 * scanFile() and scanFileWithPreview() read the file through the file driver of amphp, a worker the event loop does
 * not wait for. These tests use the driver an application gets, real files, and a worker that is stopped when a call
 * into the operating system has to hang.
 */

afterEach(function (): void {
    FileWorkers::useDefaultDriver();
    FileWorkers::removeFiles();
});

const FILE_IO_CLEAN = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";
const FILE_IO_CONTINUE = "ICAP/1.0 100 Continue\r\n\r\n";

function fileIoClient(ScriptedTransport $transport, float $operationTimeout = 60.0): IcapClient
{
    return new IcapClient(
        new Config('icap.example', operationTimeout: $operationTimeout),
        $transport,
        new RequestFormatter(),
        new ResponseParser(),
    );
}

/** The open files of a worker can only be listed where `/proc` or `lsof` exists. */
function fileIoNeedsInspection(): void
{
    if (!FileWorkers::canInspect()) {
        Assert::markTestSkipped('The open files of a worker cannot be listed here.');
    }
}

function fileIoThrown(\Closure $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * A FIFO. The operating system makes the open of it wait for a writer, so a worker that opened it would not answer
 * again.
 */
function fileIoFifo(): string
{
    $fifo = sys_get_temp_dir() . '/icap-io-fifo-' . bin2hex(random_bytes(6));
    posix_mkfifo($fifo, 0600);

    return $fifo;
}

function fileIoDropFifo(string $fifo): void
{
    // A worker that still waits in the open of the FIFO is let go; it would stay busy for ever otherwise.
    $release = fopen($fifo, 'r+');
    if ($release !== false) {
        fclose($release);
    }
    unlink($fifo);
}

it('keeps the event loop running while the file to scan is opened', function () {
    $pid = FileWorkers::own();
    $path = FileWorkers::file('payload');
    $ticks = 0;
    $timer = EventLoop::repeat(0.02, static function () use (&$ticks): void {
        $ticks++;
    });
    $transport = new ScriptedTransport();

    // The driver answers after 0.6 s, like a file system that is slow to open a file.
    FileWorkers::stop($pid);
    $resume = EventLoop::delay(0.6, static fn () => FileWorkers::resume($pid));
    try {
        fileIoClient($transport)->scanFile('/svc', $path)->await();
    } finally {
        EventLoop::cancel($timer);
        EventLoop::cancel($resume);
        FileWorkers::resume($pid);
    }

    // About 30 ticks fit into the wait; a loop held by the open gets none.
    expect($ticks)->toBeGreaterThan(10)
        ->and($transport->requests)->toHaveCount(1)
        ->and($transport->requests[0])->toContain('payload');
});

it('refuses what is not a regular file before it opens it, and sends nothing', function () {
    // One worker: an open that waited for a writer of the FIFO would hold it, and the scan at the end would hang.
    FileWorkers::own();
    $fifo = fileIoFifo();
    $file = FileWorkers::file('payload');
    $link = sys_get_temp_dir() . '/icap-io-link-' . bin2hex(random_bytes(6));
    symlink($file, $link);
    $deviceLink = sys_get_temp_dir() . '/icap-io-link-' . bin2hex(random_bytes(6));
    symlink('/dev/null', $deviceLink);

    $cases = [
        'a device'             => '/dev/null',
        'a link to a device'   => $deviceLink,
        'a directory'          => sys_get_temp_dir(),
        'a FIFO'               => $fifo,
        'a stream wrapper URL' => 'data://text/plain;base64,' . base64_encode('payload'),
    ];

    try {
        foreach ($cases as $case => $path) {
            $transport = new ScriptedTransport();
            $started = hrtime(true);

            $thrown = fileIoThrown(fn () => fileIoClient($transport, 5.0)->scanFile('/svc', $path)->await());

            expect($thrown)->toBeInstanceOf(IcapStorageException::class, $case)
                ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0)
                ->and($transport->requests)->toBe([], $case);
        }

        // A link to a regular file is a regular file; and the only worker is free.
        $transport = new ScriptedTransport();
        fileIoClient($transport, 5.0)->scanFile('/svc', $link)->await();
        expect($transport->requests[0])->toContain('payload');
    } finally {
        fileIoDropFifo($fifo);
        unlink($link);
        unlink($deviceLink);
    }
});

it('reads through worker processes of its own, whatever file driver the application configured', function () {
    // The application's choice for its own files: a driver that holds the event loop for every call.
    filesystem(new BlockingFilesystemDriver());
    $path = FileWorkers::file('payload');
    $workers = [];

    try {
        fileIoClient(new ScriptedTransport())->scanFile('/svc', $path)->await();

        // The workers the scan has started do not answer. A scan that used the driver of the application would not notice.
        $workers = FileWorkers::pids();
        foreach ($workers as $pid) {
            FileWorkers::stop($pid);
        }
        $ticks = 0;
        $timer = EventLoop::repeat(0.02, static function () use (&$ticks): void {
            $ticks++;
        });
        $started = hrtime(true);

        try {
            $thrown = fileIoThrown(fn () => fileIoClient(new ScriptedTransport(), 0.2)->scanFile('/svc', $path)->await());
        } finally {
            EventLoop::cancel($timer);
        }
    } finally {
        foreach ($workers as $pid) {
            FileWorkers::resume($pid);
        }
        filesystem(new StatusCachingFilesystemDriver(createDefaultDriver()));
    }

    // The operation ends at its own timeout while the loop runs on.
    expect($workers)->not->toBe([])
        ->and($thrown)->toBeInstanceOf(IcapTimeoutException::class)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
        ->and($ticks)->toBeGreaterThan(5);
});

it('looks up the type of a path without waiting for the workers that hold open files', function () {
    $path = FileWorkers::file('payload');
    $files = [];

    try {
        // Every open file holds a worker, up to the most the driver has.
        for ($i = 0; $i < ParallelFilesystemDriver::DEFAULT_WORKER_LIMIT; $i++) {
            $files[] = LocalFile::open($path, new NullCancellation());
        }

        // A lookup that waited for a worker would wait for one of these files to be closed.
        $started = hrtime(true);
        $files[] = LocalFile::open($path, OperationDeadline::begin(null, 3.0));

        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
    } finally {
        foreach ($files as $file) {
            $file->discard();
        }
    }
});

it('ends at the operation timeout while the file driver does not answer and closes what it delivers late', function () {
    fileIoNeedsInspection();
    foreach (['before the file is opened', 'while the file is read'] as $when) {
        $pid = FileWorkers::own();
        $path = FileWorkers::file(str_repeat('A', 200000));
        $transport = new ScriptedTransport([], static function (int $index) use ($pid, $when): void {
            if ($index === 0 && $when === 'while the file is read') {
                FileWorkers::stop($pid);
            }
        });

        if ($when === 'before the file is opened') {
            FileWorkers::stop($pid);
        }
        $ticks = 0;
        $timer = EventLoop::repeat(0.02, static function () use (&$ticks): void {
            $ticks++;
        });
        $started = hrtime(true);
        try {
            $thrown = fileIoThrown(fn () => fileIoClient($transport, 0.4)->scanFile('/svc', $path)->await());
        } finally {
            EventLoop::cancel($timer);
            FileWorkers::resume($pid);
        }
        $elapsed = (hrtime(true) - $started) / 1e9;

        expect($thrown)->toBeInstanceOf(IcapTimeoutException::class, $when)
            ->and($elapsed)->toBeGreaterThan(0.35)->toBeLessThan(2.0)
            // The loop ran on while the call hung.
            ->and($ticks)->toBeGreaterThan(5)
            // The worker runs again: the handle it opened, or still held, is closed and nobody reads from it.
            ->and(FileWorkers::settles(static fn (): bool => !FileWorkers::holds($path)))->toBeTrue($when);

    }
});

it('keeps the cancellation of the caller a cancellation while the file driver does not answer', function () {
    $pid = FileWorkers::own();
    $path = FileWorkers::file(str_repeat('A', 1000));
    $cancel = new DeferredCancellation();
    $timer = EventLoop::delay(0.2, static fn () => $cancel->cancel());

    FileWorkers::stop($pid);
    $started = hrtime(true);
    try {
        $thrown = fileIoThrown(fn () => fileIoClient(new ScriptedTransport())
            ->scanFile('/svc', $path, cancellation: $cancel->getCancellation())->await());
    } finally {
        FileWorkers::resume($pid);
        EventLoop::cancel($timer);
    }

    expect($thrown)->toBeInstanceOf(CancelledException::class)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0);
});

it('sends no more calls to a worker that does not answer than it has places for', function () {
    fileIoNeedsInspection();
    $pid = FileWorkers::own();
    $path = FileWorkers::file('payload');

    // What reaches the driver is what a worker has to take, and to do once it answers again.
    $lookups = 0;
    $opens = 0;
    /** @var FilesystemDriver&\Mockery\MockInterface $counting */
    $counting = Mockery::mock(FilesystemDriver::class);
    /** @var \Mockery\Expectation $lookup */
    $lookup = $counting->shouldReceive('getStatus');
    $lookup->andReturnUsing(static function (string $path) use (&$lookups) {
        $lookups++;

        return FileWorkers::ownDriver()->getStatus($path);
    });
    /** @var \Mockery\Expectation $open */
    $open = $counting->shouldReceive('openFile');
    $open->andReturnUsing(static function (string $path, string $mode) use (&$opens) {
        $opens++;

        return FileWorkers::ownDriver()->openFile($path, $mode);
    });
    FileCalls::use($counting);
    $client = fileIoClient(new ScriptedTransport(), 0.015);

    FileWorkers::stop($pid);
    try {
        $outcomes = [];
        // Each scan ends at its timeout. Its call does not: it is the worker's to answer.
        for ($i = 0; $i < FileCalls::MAX_PENDING + 16; $i++) {
            $thrown = fileIoThrown(fn () => $client->scanFile('/svc', $path)->await());
            $outcomes[] = $thrown === null ? 'none' : $thrown::class;
        }
        $lookedUp = $lookups;
    } finally {
        FileWorkers::resume($pid);
    }

    // The worker answers again, and nothing is opened for the scans that are over: the places are free.
    $transport = new ScriptedTransport();
    fileIoClient($transport)->scanFile('/svc', $path)->await();

    expect(array_unique($outcomes))->toBe([IcapTimeoutException::class])
        ->and($lookedUp)->toBe(FileCalls::MAX_PENDING)
        ->and($opens)->toBe(1)
        ->and($transport->requests[0])->toContain('payload')
        ->and(FileWorkers::settles(static fn (): bool => !FileWorkers::holds($path)))->toBeTrue();
});

it('fails a scan whose request did not take the whole file', function () {
    // A formatter written for streams and strings: it sends an empty body for what it does not know.
    $formatter = new class () implements RequestFormatterInterface {
        #[\Override]
        public function format(IcapRequest $request): iterable
        {
            $envelope = $request->encapsulatedResponse;
            if ($envelope !== null && !is_string($envelope->body) && !is_resource($envelope->body)) {
                $request = new IcapRequest(
                    method: $request->method,
                    uri: $request->uri,
                    headers: $request->headers,
                    encapsulatedResponse: new HttpResponse($envelope->statusCode, $envelope->reasonPhrase, $envelope->headers, ''),
                );
            }

            return (new RequestFormatter())->format($request);
        }
    };
    $path = FileWorkers::file('payload to scan');
    $cases = [
        'scanFile'                    => [static fn (IcapClient $client) => $client->scanFile('/svc', $path), []],
        'full request after a preview' => [static fn (IcapClient $client) => $client->scanFileWithPreview('/svc', $path, 4), [FILE_IO_CONTINUE, FILE_IO_CLEAN]],
    ];

    foreach ($cases as $case => [$scan, $replies]) {
        $client = new IcapClient(new Config('icap.example'), new ScriptedTransport($replies), $formatter, new ResponseParser());

        // The server answers "clean" to the empty body it got; that is no verdict on the file.
        $thrown = fileIoThrown(fn () => $scan($client)->await());

        expect($thrown)->toBeInstanceOf(IcapStorageException::class, $case);
    }
});

it('lets the failure of the scan and the end of the operation decide the result, not a failure to close the file', function () {
    $closeFails = static function (): void {
        throw new StreamException('The close failed.');
    };
    $holdsTheThread = static function (): void {
        usleep(300_000);
    };
    $cases = [
        // label => [scan, source, operation timeout, exception that leaves, part of its message]
        'a clean verdict, the close fails'                 => ['scanFile', fn () => ScriptedSource::add('case', 'abcdefgh', onClose: $closeFails), 60.0, IcapStorageException::class, 'Unable to close'],
        'a clean verdict after a preview, the close fails' => ['preview', fn () => ScriptedSource::add('case', 'abcdefgh', onClose: $closeFails), 60.0, IcapStorageException::class, 'Unable to close'],
        'the read fails, and the close'                    => ['scanFile', fn () => ScriptedSource::add('case', 'abcdefgh', failAfter: 2, onClose: $closeFails), 60.0, IcapStorageException::class, 'Unable to read'],
        'the read fails after the operation timeout'       => ['scanFile', fn () => ScriptedSource::add('case', 'abcdefgh', failAfter: 0, onRead: $holdsTheThread), 0.1, IcapTimeoutException::class, ''],
    ];

    foreach ($cases as $case => [$scan, $source, $timeout, $expected, $message]) {
        $path = $source();
        $client = fileIoClient(new ScriptedTransport($scan === 'preview' ? [FILE_IO_CONTINUE, FILE_IO_CLEAN] : []), $timeout);
        try {
            $thrown = fileIoThrown(fn () => $scan === 'scanFile'
                ? $client->scanFile('/svc', $path)->await()
                : $client->scanFileWithPreview('/svc', $path, 4)->await());

            expect($thrown)->toBeInstanceOf($expected, $case)
                ->and($thrown?->getMessage())->toContain($message);
        } finally {
            FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened);
            ScriptedSource::reset();
        }
    }
});

it('serves parallel scans with a bounded number of workers and closes every handle', function () {
    fileIoNeedsInspection();
    FileWorkers::useDefaultDriver();
    $known = FileWorkers::pids();
    $content = random_bytes(300000);
    $paths = [];
    for ($i = 0; $i < 24; $i++) {
        $paths[] = FileWorkers::file($content);
    }
    $transport = new ScriptedTransport();
    $client = fileIoClient($transport);

    await(array_map(static fn (string $path) => $client->scanFile('/svc', $path), $paths));

    // A worker for every scan would be 24. The most is the limit of the files, and one more for the lookups.
    $started = count(array_diff(FileWorkers::pids(), $known));
    expect($started)->toBeLessThanOrEqual(ParallelFilesystemDriver::DEFAULT_WORKER_LIMIT + 1)
        ->and(FileWorkers::pids())->not->toBe([])
        ->and($transport->requests)->toHaveCount(24);
    foreach ($transport->requests as $wire) {
        // The body goes out in the blocks it was read in: 64 KiB (0x10000) and the rest.
        expect($wire)->toContain(substr($content, 1000, 64))
            ->and($wire)->toContain("\r\n10000\r\n");
    }

    $names = array_map('realpath', $paths);
    expect(FileWorkers::settles(static fn (): bool => array_intersect($names, FileWorkers::openFiles()) === []))->toBeTrue();
});

it('scans a large file in bounded memory', function () {
    $path = FileWorkers::sparseFile(48 * 1024 * 1024);

    $sink = new class () implements TransportInterface {
        public int $bytes = 0;

        #[\Override]
        public function request(Config $config, iterable $rawRequest, ?Cancellation $cancellation = null): Future
        {
            foreach ($rawRequest as $chunk) {
                $this->bytes += strlen($chunk);
            }

            return Future::complete(FILE_IO_CLEAN);
        }
    };
    $client = new IcapClient(new Config('icap.example'), $sink, new RequestFormatter(), new ResponseParser());

    // The first scan loads the classes and starts the worker.
    $client->scanFile('/svc', $path)->await();
    $sink->bytes = 0;

    memory_reset_peak_usage();
    $base = memory_get_usage();
    $client->scanFile('/svc', $path)->await();

    expect($sink->bytes)->toBeGreaterThan(48 * 1024 * 1024)
        ->and(memory_get_peak_usage() - $base)->toBeLessThan(8 * 1024 * 1024);
});

it('closes the file when the deadline ends a scan that is stuck writing to its socket', function () {
    fileIoNeedsInspection();

    // The server accepts and never reads: the upload fills the socket buffers and waits.
    $path = FileWorkers::sparseFile(64 * 1024 * 1024);

    $server = Socket\listen('127.0.0.1:0');
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    $peers = [];
    $accepting = async(static function () use ($server, &$peers): void {
        while (($peer = $server->accept()) !== null) {
            $peers[] = $peer;
        }
    });
    $client = new IcapClient(
        new Config('127.0.0.1', $address->getPort(), streamTimeout: 30.0, operationTimeout: 0.8),
        new AsyncAmpTransport(),
        new RequestFormatter(),
        new ResponseParser(),
    );
    $heldWhileStuck = null;
    $look = EventLoop::delay(0.4, static function () use (&$heldWhileStuck, $path): void {
        $heldWhileStuck = FileWorkers::holds($path);
    });

    try {
        $thrown = fileIoThrown(fn () => $client->scanFile('/svc', $path)->await());
    } finally {
        EventLoop::cancel($look);
        $server->close();
        foreach ($peers as $peer) {
            $peer->close();
        }
        $accepting->await();
    }

    expect($thrown)->toBeInstanceOf(IcapTimeoutException::class)
        ->and($heldWhileStuck)->toBeTrue()
        ->and(FileWorkers::settles(static fn (): bool => !FileWorkers::holds($path)))->toBeTrue();
});

it('reads the file it opened when the path is replaced during the scan', function () {
    $path = FileWorkers::file(str_repeat('OLD', 1000));
    $transport = new ScriptedTransport([], static function (int $index) use ($path): void {
        if ($index === 0) {
            file_put_contents($path . '.new', str_repeat('NEW', 5000));
            rename($path . '.new', $path);
        }
    });

    fileIoClient($transport)->scanFile('/svc', $path)->await();

    // Size and bytes belong to the file that was opened, not to what the path names by now.
    expect($transport->requests[0])->toContain("Content-Length: 3000\r\n")
        ->and($transport->requests[0])->toContain(str_repeat('OLD', 1000))
        ->and($transport->requests[0])->not->toContain('NEW');
});

it('fails before the final chunk when the file changes its size during the transfer', function () {
    $cases = [
        'grows'   => [static fn (string $path): mixed => file_put_contents($path, 'x', FILE_APPEND), 'holds more'],
        'shrinks' => [static fn (string $path): mixed => file_put_contents($path, 'short'), 'ended after'],
    ];

    foreach ($cases as $case => [$change, $message]) {
        foreach (['scanFile', 'full request after a preview'] as $scan) {
            $path = FileWorkers::file(str_repeat('A', 20000));
            $replies = $scan === 'scanFile' ? [] : [FILE_IO_CONTINUE, FILE_IO_CLEAN];
            // The request that carries the whole file is the only one for scanFile(), the second after a preview.
            $request = $scan === 'scanFile' ? 0 : 1;
            $heads = 0;
            $transport = new ScriptedTransport($replies, static function (int $index) use ($change, $path, $request, &$heads): void {
                if ($index === 0 && $heads++ === $request) {
                    $change($path);
                }
            });
            $client = fileIoClient($transport);

            $thrown = fileIoThrown(fn () => $scan === 'scanFile'
                ? $client->scanFile('/svc', $path)->await()
                : $client->scanFileWithPreview('/svc', $path, 4)->await());

            expect($thrown)->toBeInstanceOf(IcapStorageException::class, "$case, $scan")
                ->and($thrown?->getMessage())->toContain($message)
                ->and($transport->requests[$request])->not->toEndWith("0\r\n\r\n");

        }
    }
});

it('opens a relative path against the working directory of the call', function () {
    $directory = sys_get_temp_dir() . '/icap-io-cwd-' . bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory . '/relative.bin', 'payload');
    $cwd = getcwd();
    // The worker starts in the directory the process is in now and keeps it.
    FileWorkers::own();
    $transport = new ScriptedTransport();

    try {
        chdir($directory);
        fileIoClient($transport)->scanFile('/svc', 'relative.bin')->await();
    } finally {
        chdir((string) $cwd);
        unlink($directory . '/relative.bin');
        rmdir($directory);
    }

    expect($transport->requests[0])->toContain('payload');
});

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
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\File\FilesystemException;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\FileCalls;
use Ndrstmr\Icap\LocalFile;
use Ndrstmr\Icap\OperationDeadline;
use PHPUnit\Framework\Assert;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

afterEach(function (): void {
    FileWorkers::useDefaultDriver();
    FileWorkers::removeFiles();
});

function localFileThrown(\Closure $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * Reads that hang in the driver: the files are opened while the places are free, then each read takes one. A file is
 * let go when its read has returned.
 *
 * @return list<Future<mixed>>
 */
function localFileHangingReads(string $path, int $count): array
{
    $files = [];
    for ($i = 0; $i < $count; $i++) {
        $files[] = LocalFile::open($path, new NullCancellation());
    }

    $reads = [];
    foreach ($files as $file) {
        $reads[] = async(static function () use ($file): ?\Throwable {
            $thrown = localFileThrown(static fn () => $file->read(10));
            $file->discard();

            return $thrown;
        });
    }

    return $reads;
}

it('reads a file in bounded blocks to its confirmed end, on the size the open file has', function () {
    $content = random_bytes(LocalFile::BLOCK_SIZE * 2 + 123);
    $file = LocalFile::open(FileWorkers::file($content), new NullCancellation());

    expect($file->size)->toBe(strlen($content));

    // However much is asked for, a read brings at most one block.
    $read = '';
    $largest = 0;
    while (($block = $file->read(PHP_INT_MAX)) !== null) {
        $largest = max($largest, strlen($block));
        $read .= $block;
    }
    expect($read)->toBe($content)
        ->and($largest)->toBe(LocalFile::BLOCK_SIZE)
        // The end stays the end, and a rewind starts the file again.
        ->and($file->read(1))->toBeNull();

    // A request has read the file once its end is confirmed, and no longer after the file went back to its start.
    $file->assertRead();
    $file->rewind();
    expect(localFileThrown(static fn () => $file->assertRead()))->toBeInstanceOf(IcapStorageException::class)
        ->and($file->read(5))->toBe(substr($content, 0, 5))
        ->and(localFileThrown(static fn () => $file->assertRead()))->toBeInstanceOf(IcapStorageException::class);

    $file->close();
    $file->close();
    expect(fn () => $file->read(1))->toThrow(\LogicException::class);
});

it('begins no driver call for an operation that is over', function () {
    $path = ScriptedSource::add('over', 'payload');
    $cancel = new DeferredCancellation();

    try {
        $file = LocalFile::open($path, $cancel->getCancellation());
        $cancel->cancel();

        $thrown = localFileThrown(static fn () => $file->read(10));
        delay(0.05);

        expect($thrown)->toBeInstanceOf(CancelledException::class)
            ->and(ScriptedSource::$reads)->toBe(0);
    } finally {
        ScriptedSource::reset();
    }
});

it('closes a file that the driver opens after the operation gave up on it', function () {
    $path = ScriptedSource::add('late', 'payload', openDelay: 0.2);

    try {
        $thrown = localFileThrown(static fn () => LocalFile::open($path, new TimeoutCancellation(0.05)));
        delay(0.4);

        expect($thrown)->toBeInstanceOf(CancelledException::class)
            // The open went on in the driver, and what it delivered was closed.
            ->and(ScriptedSource::$opened)->toBe(1)
            ->and(ScriptedSource::$closed)->toBe(1);
    } finally {
        ScriptedSource::reset();
    }
});

it('reports what the driver cannot do as an IcapStorageException with the driver exception as its cause', function () {
    $cases = [
        // label => [path, the cause]
        'a file that does not exist'      => [sys_get_temp_dir() . '/icap-io-missing-' . bin2hex(random_bytes(6)), FilesystemException::class],
        // Whatever the operating system lets a seek do with it: a device is no regular file.
        'a device'                        => ['/dev/null', FilesystemException::class],
        'a file that has no size or end'  => [ScriptedSource::add('nosize', 'payload', statFails: true), StreamException::class],
    ];

    try {
        foreach ($cases as $case => [$path, $cause]) {
            $thrown = localFileThrown(static fn () => LocalFile::open($path, new NullCancellation()));

            expect($thrown)->toBeInstanceOf(IcapStorageException::class, $case)
                ->and($thrown?->getPrevious())->toBeInstanceOf($cause, $case);
        }
    } finally {
        FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened);
        ScriptedSource::reset();
    }
});

it('ends the wait for a hung driver call at the cancellation and closes the file when the call returns', function () {
    if (!FileWorkers::canInspect()) {
        Assert::markTestSkipped('The open files of a worker cannot be listed here.');
    }

    $pid = FileWorkers::own();
    $path = FileWorkers::file(str_repeat('A', 1000));
    $file = LocalFile::open($path, new TimeoutCancellation(0.6));

    // A call into an operating system that hangs: the worker does not answer.
    FileWorkers::stop($pid);
    try {
        $started = hrtime(true);
        $thrown = localFileThrown(static fn () => $file->read(10));
        $read = (hrtime(true) - $started) / 1e9;

        $started = hrtime(true);
        $closeThrown = localFileThrown(static fn () => $file->close());
        $close = (hrtime(true) - $started) / 1e9;
    } finally {
        FileWorkers::resume($pid);
    }

    expect($thrown)->toBeInstanceOf(CancelledException::class)
        ->and($read)->toBeLessThan(2.0)
        // The close is queued behind the call that hangs; the operation is over and does not wait for it.
        ->and($closeThrown)->toBeInstanceOf(CancelledException::class)
        ->and($close)->toBeLessThan(0.5)
        ->and(FileWorkers::settles(static fn (): bool => !FileWorkers::holds($path)))->toBeTrue();
});

it('lets no more driver calls hang than it has places, and frees a place only when its call returns', function () {
    $hold = new DeferredFuture();
    $path = ScriptedSource::add('hung', 'payload', onRead: static fn () => $hold->getFuture()->await());
    $cancel = new DeferredCancellation();
    $laterCancel = new DeferredCancellation();

    try {
        // Opened while the places are free.
        $later = LocalFile::open($path, $laterCancel->getCancellation());
        $files = [];
        for ($i = 0; $i < FileCalls::MAX_PENDING + 20; $i++) {
            $files[] = LocalFile::open($path, $cancel->getCancellation());
        }

        // Every read hangs in the driver. The ones beyond the places wait for a place, and none of them reaches it.
        $reads = array_map(
            static fn (LocalFile $file) => async(static fn () => localFileThrown(static fn () => $file->read(10))),
            $files,
        );
        delay(0.05);
        $cancel->cancel();
        $thrown = array_map(static fn ($read) => $read->await(), $reads);

        expect(ScriptedSource::$reads)->toBe(FileCalls::MAX_PENDING)
            ->and(array_unique(array_map(static fn (mixed $e): string => $e instanceof \Throwable ? $e::class : '', $thrown)))
            ->toBe([CancelledException::class]);

        // Their operations gave up, their calls did not return: another read still finds no place.
        $timer = EventLoop::delay(0.05, static fn () => $laterCancel->cancel());
        $starved = localFileThrown(static fn () => $later->read(10));
        EventLoop::cancel($timer);
        expect($starved)->toBeInstanceOf(CancelledException::class)
            ->and(ScriptedSource::$reads)->toBe(FileCalls::MAX_PENDING);

        // The driver lets go of the calls, and the places come back with them.
        $hold->complete();
        delay(0.05);
        $again = LocalFile::open($path, new NullCancellation());
        expect($again->read(10))->toBe('payload');

        foreach ([...$files, $later, $again] as $file) {
            $file->discard();
        }
        expect(FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened))->toBeTrue();
    } finally {
        $hold->isComplete() || $hold->complete();
        ScriptedSource::reset();
    }
});

it('begins no driver call for an operation that ended while its call waited for a place or for its turn', function () {
    $cases = [
        // label => [whether every place is taken, how the operation ends]
        'a place is handed over as the caller cancels'          => [true, 'cancel'],
        'a place is handed over when the clock is past the end' => [true, 'clock'],
        'the caller cancels once the call is scheduled'         => [false, 'cancel'],
        'the clock is past the end once the call is scheduled'  => [false, 'clock'],
    ];

    foreach ($cases as $case => [$taken, $ending]) {
        $hold = new DeferredFuture();
        $path = ScriptedSource::add('case', 'payload', onRead: static fn () => $hold->getFuture()->await());
        $cancel = new DeferredCancellation();
        // Only the clock tells that this one is over: the thread is held below, and its timer cannot fire.
        $operation = $ending === 'clock' ? OperationDeadline::begin(null, 0.1) : $cancel->getCancellation();

        try {
            $hung = localFileHangingReads($path, $taken ? FileCalls::MAX_PENDING : 0);
            delay(0.05);
            $lookups = ScriptedSource::$lookups;

            $opening = async(static fn () => localFileThrown(static fn () => LocalFile::open($path, $operation)));
            if ($taken) {
                delay(0.005);
                if ($ending === 'cancel') {
                    // The places come back, and the caller cancels, in the same turn of the loop.
                    $hold->complete();
                    $cancel->cancel();
                } else {
                    usleep(150_000);
                    $hold->complete();
                }
            } else {
                async(static function () use ($ending, $cancel): void {
                    $ending === 'cancel' ? $cancel->cancel() : usleep(150_000);
                });
            }
            $thrown = $opening->await();

            expect($thrown)->toBeInstanceOf(CancelledException::class, $case)
                ->and(ScriptedSource::$lookups)->toBe($lookups, $case);

            // The place that was handed over went on once: all of them are free, no more and no fewer.
            $hold->isComplete() || $hold->complete();
            array_map(static fn (Future $read) => $read->await(), $hung);
            $again = new DeferredFuture();
            $probe = ScriptedSource::add('probe', 'payload', onRead: static fn () => $again->getFuture()->await());
            $reads = ScriptedSource::$reads;
            $probing = localFileHangingReads($probe, FileCalls::MAX_PENDING + 1);
            delay(0.05);
            $reached = ScriptedSource::$reads - $reads;
            $again->complete();
            array_map(static fn (Future $read) => $read->await(), $probing);

            expect($reached)->toBe(FileCalls::MAX_PENDING, $case);
        } finally {
            if ($operation instanceof OperationDeadline) {
                $operation->close();
            }
            $hold->isComplete() || $hold->complete();
            FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened, 0.5);
            ScriptedSource::reset();
        }
    }
});

it('holds a place for the close of a file as well, until the driver has returned', function () {
    $hold = new DeferredFuture();
    $path = ScriptedSource::add('closing', 'payload', onClose: static fn () => $hold->getFuture()->await());

    try {
        $files = [];
        for ($i = 0; $i < FileCalls::MAX_PENDING + 10; $i++) {
            $files[] = LocalFile::open($path, new NullCancellation());
        }
        foreach ($files as $file) {
            $file->discard();
        }
        delay(0.05);

        // The closes that hang have the places, and the others wait for them.
        expect(ScriptedSource::$closed)->toBe(FileCalls::MAX_PENDING);

        $hold->complete();
        expect(FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened))->toBeTrue();
    } finally {
        $hold->isComplete() || $hold->complete();
        ScriptedSource::reset();
    }
});

it('gives the end of the operation precedence over a driver failure, and passes what is no failure of the file', function () {
    $fails = static function (): void {
        throw new StreamException('The read failed.');
    };

    $cases = [
        // label => [what the read does, the cancellation of the file, the exception that leaves]
        'the caller cancels as the read fails' => static function () use ($fails): array {
            $cancel = new DeferredCancellation();

            return [
                static function () use ($cancel, $fails): void {
                    $cancel->cancel();
                    $fails();
                },
                $cancel->getCancellation(),
                CancelledException::class,
            ];
        },
        'the read fails after the end of the operation' => static fn (): array => [
            // A driver that holds the thread: the timer of the operation cannot fire, but the clock has passed its end.
            static function () use ($fails): void {
                usleep(300_000);
                $fails();
            },
            OperationDeadline::begin(null, 0.1),
            CancelledException::class,
        ],
        'a failure of the file' => static fn (): array => [$fails, new NullCancellation(), IcapStorageException::class],
        'a failure that is none of the file' => static fn (): array => [
            static function (): void {
                throw new \LogicException('Not a failure of the file.');
            },
            new NullCancellation(),
            \LogicException::class,
        ],
    ];

    foreach ($cases as $case => $build) {
        [$onRead, $cancellation, $expected] = $build();
        try {
            $file = LocalFile::open(ScriptedSource::add('case', 'payload', onRead: $onRead), $cancellation);
            $thrown = localFileThrown(static fn () => $file->read(10));
            $file->discard();

            expect($thrown)->toBeInstanceOf($expected, $case);
            if ($expected === IcapStorageException::class) {
                expect($thrown?->getPrevious())->toBeInstanceOf(StreamException::class, $case);
            }
        } finally {
            if ($cancellation instanceof OperationDeadline) {
                $cancellation->close();
            }
            FileWorkers::settles(static fn (): bool => ScriptedSource::$closed === ScriptedSource::$opened);
            ScriptedSource::reset();
        }
    }
});

it('reports a file that cannot be closed when the scan is done, and keeps quiet about one that is let go', function () {
    $path = ScriptedSource::add('close', 'payload', onClose: static function (): void {
        throw new StreamException('The close failed.');
    });

    try {
        $done = LocalFile::open($path, new NullCancellation());
        $thrown = localFileThrown(static fn () => $done->close());

        $letGo = LocalFile::open($path, new NullCancellation());
        $letGo->discard();
        delay(0.05);

        expect($thrown)->toBeInstanceOf(IcapStorageException::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(StreamException::class)
            // Both handles were closed, and the second one said nothing.
            ->and(ScriptedSource::$closed)->toBe(2);
    } finally {
        ScriptedSource::reset();
    }
});

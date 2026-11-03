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

use Ndrstmr\Icap\Tests\AsyncTestCase;
use PHPUnit\Framework\Assert;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;

use function Amp\delay;

/*
 * The second test file of a child of tests/FixtureCleanupTest.php. It makes the first load of a class fail in the test
 * that runs before it, says in the failure what that test held at that moment, and then looks at what is left of it.
 */

// Its own process group: the test can end this child with its workers, stopped or not, and nothing else.
// A child whose test is gone ends by itself: the parent of the process changes when it ends.
posix_setsid();
$parent = posix_getppid();
EventLoop::unreference(EventLoop::repeat(0.5, static function () use ($parent): void {
    if (posix_getppid() !== $parent) {
        posix_kill(-posix_getpid(), 9);
    }
}));

$sockets = static fn (): int => count(array_filter(
    get_resources('stream'),
    static fn ($stream): bool => str_starts_with(stream_get_meta_data($stream)['stream_type'], 'tcp_socket'),
));
// The timers of a test are referenced; those of the file workers and the one above are not.
$timers = static fn (): array => array_filter(
    EventLoop::getIdentifiers(),
    static fn (string $id): bool => EventLoop::isReferenced($id) && in_array(EventLoop::getType($id), [CallbackType::Delay, CallbackType::Repeat], true),
);

$failing = explode(',', (string) getenv('ICAP_FAILING_CLASSES'));
$failure = null;
$timersAtFailure = [];
spl_autoload_register(static function (string $class) use (&$failing, &$failure, &$timersAtFailure, $sockets, $timers): void {
    if (!in_array($class, $failing, true)) {
        return;
    }
    if ($failure === null) {
        $timersAtFailure = $timers();
        $failure = sprintf('INJECTED_FAILURE sockets=%d timers=%d', $sockets(), count($timersAtFailure));
    }
    if (getenv('ICAP_FAILURE_KIND') === 'assertion') {
        Assert::fail($failure);
    }

    throw new RuntimeException($failure);
}, true, true);

uses(AsyncTestCase::class);

it('runs to its end and finds nothing left', function () use (&$failing, &$timersAtFailure, $sockets, $timers) {
    $failing = [];
    // A timer that was only unreferenced is not gone.
    $left = [$sockets(), array_values(array_unique([...$timers(), ...array_intersect($timersAtFailure, EventLoop::getIdentifiers())]))];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(static function (): void {
        delay(0.01);
    });

    expect($left)->toBe([0, []]);
});

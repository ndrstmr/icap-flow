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

use Ndrstmr\Icap\Cache\Psr16OptionsCache;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Tests\Cache\FileSimpleCache;
use PHPUnit\Framework\Assert;

/*
 * Two processes on one backend: what the contract of the shared cache promises, checked where it is shared. An entry is
 * visible to the other process, ends at its own end by the stored time and not by anybody's reading, and a tag of one
 * service invalidates nothing of another; the last writer of a key wins.
 */

const SHARED_CACHE_CHILD = <<<'PHP'
require $argv[1];
set_time_limit(30);
$job = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$offset = $job['offset'];
$cache = new Ndrstmr\Icap\Cache\Psr16OptionsCache(
    new Ndrstmr\Icap\Tests\Cache\FileSimpleCache($job['dir']),
    'shared.',
    static fn (): int => time() + $offset,
);
foreach ($job['write'] as $write) {
    $cache->set(
        $write['key'],
        new Ndrstmr\Icap\DTO\IcapResponse(200, ['Service' => [$write['service']]], $write['body']),
        $write['ttl'],
        $write['tag'],
    );
}
$read = [];
foreach ($job['read'] as $key) {
    $response = $cache->get($key);
    $read[$key] = $response === null ? null : ['service' => $response->headers['Service'][0] ?? null, 'body' => $response->body];
}
echo json_encode($read, JSON_THROW_ON_ERROR);
PHP;

/**
 * Runs a second PHP process on the directory: it writes, then reads, with a clock that is $offset seconds ahead.
 *
 * @param list<string>                                                           $read
 * @param list<array{key: string, service: string, body: string, ttl: int, tag: string}> $write
 *
 * @return array<string, array{service: ?string, body: string}|null>
 */
function sharedCacheChild(string $directory, int $offset, array $read, array $write = []): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-r', SHARED_CACHE_CHILD, '--', dirname(__DIR__, 2) . '/vendor/autoload.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    Assert::assertIsResource($process);
    fwrite($pipes[0], json_encode(['dir' => $directory, 'offset' => $offset, 'read' => $read, 'write' => $write], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    Assert::assertSame(0, $status, 'The second process failed: ' . $errors);

    /** @var array<string, array{service: ?string, body: string}|null> $result */
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    return $result;
}

it('shares entries between two processes under the contract of the cache', function () {
    $directory = sys_get_temp_dir() . '/icap-shared-cache-' . bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        $cache = new Psr16OptionsCache(new FileSimpleCache($directory), 'shared.');
        $cache->set('svc-a', new IcapResponse(200, ['Service' => ['a']], 'body-a'), 60, '"a1"');

        // The other process finds the entry, and stores one of another service under another tag.
        $seen = sharedCacheChild($directory, 0, ['svc-a'], [['key' => 'svc-b', 'service' => 'b', 'body' => 'body-b', 'ttl' => 60, 'tag' => '"b1"']]);
        expect($seen)->toBe(['svc-a' => ['service' => 'a', 'body' => 'body-a']]);
        // That tag invalidates nothing of svc-a, and svc-b is visible here.
        expect($cache->get('svc-a')?->body)->toBe('body-a');
        expect($cache->get('svc-b')?->body)->toBe('body-b');

        // The last writer of a key wins, in either process.
        $cache->set('svc-a', new IcapResponse(200, ['Service' => ['a']], 'body-a2'), 60, '"a2"');
        $seen = sharedCacheChild($directory, 0, ['svc-a', 'svc-b']);
        expect($seen['svc-a']['body'] ?? null)->toBe('body-a2');
        expect($seen['svc-b']['body'] ?? null)->toBe('body-b');

        // An entry ends at the end of the TTL it was stored with (the exact second is tested with a clock of its own).
        // The backend still holds it, and neither the reads above nor the other process change that.
        expect(sharedCacheChild($directory, 30, ['svc-a', 'svc-b']))->not->toContain(null);
        expect(sharedCacheChild($directory, 90, ['svc-a', 'svc-b']))->toBe(['svc-a' => null, 'svc-b' => null]);
        expect($cache->get('svc-a')?->body)->toBe('body-a2');
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

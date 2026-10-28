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
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapStorageException;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Tests\Cache\StoredEntryCases;
use Ndrstmr\Icap\Tests\ScriptedSource;
use Ndrstmr\Icap\Tests\ScriptedTransport;
use Psr\SimpleCache\CacheInterface;

uses(AsyncTestCase::class);

it('delegates get to PSR-16 and reconstructs IcapResponse', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $response = new IcapResponse(200, ['Preview' => ['1024']]);
    $cache->set('host:1344/svc', $response, 60);

    $result = $cache->get('host:1344/svc');
    expect($result)->not->toBeNull()
        ->and($result?->statusCode)->toBe(200)
        ->and($result?->headers['Preview'])->toBe(['1024']);
});

it('returns null on cache miss', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    expect($cache->get('nonexistent'))->toBeNull();
});

it('delegates delete to PSR-16', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('k', new IcapResponse(200), 60);
    expect($cache->get('k'))->not->toBeNull();

    $cache->delete('k');
    expect($cache->get('k'))->toBeNull();
});

it('keeps the entries of the other services when a service reports a new ISTag', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('svc1', new IcapResponse(200, ['Service' => ['one']]), 60, '"v1"');
    $cache->set('svc2', new IcapResponse(200, ['Service' => ['two']]), 60, '"v1"');
    // Each tag belongs to its own service: svc3 and svc1 report a new one, svc2 stays as it is.
    $cache->set('svc3', new IcapResponse(200, ['Service' => ['three']]), 60, '"v2"');
    $cache->set('svc1', new IcapResponse(200, ['Service' => ['one, newer']]), 60, '"v2"');

    expect($cache->get('svc1')?->headers['Service'])->toBe(['one, newer']);
    expect($cache->get('svc2')?->headers['Service'])->toBe(['two']);
    expect($cache->get('svc3')?->headers['Service'])->toBe(['three']);
});

it('does not cache when TTL is zero or negative', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('k', new IcapResponse(200), 0);
    expect($cache->get('k'))->toBeNull();

    $cache->set('k', new IcapResponse(200), -5);
    expect($cache->get('k'))->toBeNull();
});

/*
 * M12: PSR-16 keys are A-Z a-z 0-9 _ . up to 64 characters, and {}()/\@: are
 * reserved. The double below enforces that on every call, so an adapter that
 * hands a backend the caller's key, the prefix or a meta key unchanged fails.
 */
it('hands the backend only keys that follow the PSR-16 rules, whatever key the caller uses', function () {
    $keys = [
        'host, port and service'  => 'icap.example:1344/avscan',
        'IPv6 literal with query' => '[2001:db8::1]:1344/avscan?profile=a/b',
        'reserved characters'     => '{}()/\\@:',
        'empty'                   => '',
        'Unicode'                 => "d\u{e4}tenschutz \u{1F512}",
        'longer than 64'          => str_repeat('k', 300),
        'former meta name'        => '__icap_istag',
    ];

    foreach ($keys as $case => $key) {
        $inner = new ArraySimpleCache();
        $cache = new Psr16OptionsCache($inner, 'icap.');

        $cache->set($key, new IcapResponse(200, ['Preview' => ['1024']], 'body'), 60, '"istag-1"');
        expect($cache->get($key)?->statusCode)->toBe(200, $case);

        $cache->delete($key);
        expect($cache->get($key))->toBeNull($case);

        // The entry alone, under a key of 64 hex digits: no ISTag item, no list of keys.
        expect(count($inner->seen))->toBe(1, $case);
        foreach (array_keys($inner->seen) as $backendKey) {
            expect(preg_match('/^[0-9a-f]{64}\z/', (string) $backendKey))->toBe(1, "$case: $backendKey");
        }
    }
});

it('keeps the entries of two prefixes apart even when prefix and key add up to the same text', function () {
    $inner = new ArraySimpleCache();
    $a = new Psr16OptionsCache($inner, 'a');
    $ab = new Psr16OptionsCache($inner, 'ab');

    $a->set('bc', new IcapResponse(200, ['Service' => ['a']]), 60, '"v1"');
    // 'a' + 'bc' is 'ab' + 'c'.
    expect($ab->get('c'))->toBeNull();

    $ab->set('c', new IcapResponse(200, ['Service' => ['ab']]), 60, '"v1"');
    expect($a->get('bc')?->headers['Service'])->toBe(['a']);
    expect($ab->get('c')?->headers['Service'])->toBe(['ab']);

    // The same prefix finds the same entries from another adapter.
    expect((new Psr16OptionsCache($inner, 'ab'))->get('c')?->headers['Service'])->toBe(['ab']);
});

it('treats a caller key that was the name of a former meta entry like any other key', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner);

    foreach (['x', '__icap_istag', '__icap_keys', 'y'] as $name) {
        $cache->set($name, new IcapResponse(200, ['Service' => [$name]]), 60, '"v1"');
    }

    foreach (['x', '__icap_istag', '__icap_keys', 'y'] as $name) {
        expect($cache->get($name)?->headers['Service'])->toBe([$name], $name);
    }
    expect(count($inner->all()))->toBe(4);
});

it('does not read entries of the old key schemes and leaves them and foreign entries in the backend', function () {
    $inner = new ArraySimpleCache();
    $legacy = ['statusCode' => 200, 'headers' => ['Preview' => ['1']], 'body' => ''];
    // What an earlier version stored with the prefix "icap.": the entry, its ISTag and the tracked keys.
    $inner->set('icap.svc', $legacy, 60);
    $inner->set('icap.__icap_istag', '"old"');
    $inner->set('icap.__icap_keys', ['svc']);
    $inner->set('app.session', 'not ours');

    $cache = new Psr16OptionsCache($inner, 'icap.');
    expect($cache->get('svc'))->toBeNull();

    $cache->set('svc', new IcapResponse(200, ['Service' => ['new']]), 60, '"new"');
    expect($cache->get('svc')?->headers['Service'])->toBe(['new']);

    expect($inner->get('icap.svc'))->toBe($legacy);
    expect($inner->get('icap.__icap_istag'))->toBe('"old"');
    expect($inner->get('icap.__icap_keys'))->toBe(['svc']);
    expect($inner->get('app.session'))->toBe('not ours');
});

/*
 * what a shared backend returns is not trusted. A value that is not a whole, current entry for this key is a miss;
 * it neither raises a TypeError nor becomes a response.
 */
it('answers a miss for every stored value that is no entry for this key at this time', function () {
    $now = 1_000_000;
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.', static function () use (&$now): int {
        return $now;
    });

    $cache->set('svc', new IcapResponse(200, ['Preview' => ['1024'], 'ISTag' => ['"v1"']], 'opt-body'), 60, '"v1"');
    $slot = (string) array_key_first($inner->all());
    $entry = $inner->get($slot);
    expect($entry)->toBeArray();
    /** @var array<string, mixed> $entry */
    expect($cache->get('svc')?->body)->toBe('opt-body');

    foreach (StoredEntryCases::unusable($entry, $now) as $case => $value) {
        $inner->set($slot, $value);
        expect($cache->get('svc'))->toBeNull($case);
    }

    // The same slot holds the whole entry again, and so do the entries that only look like the ones above.
    $inner->set($slot, $entry);
    expect($cache->get('svc')?->headers['Preview'])->toBe(['1024']);

    foreach (StoredEntryCases::usable($entry, $now) as $case => $value) {
        $inner->set($slot, $value);
        expect($cache->get('svc'))->not->toBeNull($case);
    }
});

it('answers a miss for an entry that another key wrote to this slot', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('a', new IcapResponse(200, ['Service' => ['a']]), 60);
    $slotOfA = (string) array_key_first($inner->all());
    $cache->set('b', new IcapResponse(200, ['Service' => ['b']]), 60);
    $slotOfB = (string) array_key_last($inner->all());

    $inner->set($slotOfB, $inner->get($slotOfA));

    expect($cache->get('b'))->toBeNull();
    expect($cache->get('a')?->headers['Service'])->toBe(['a']);
});

it('ends an entry at its own end, also in a backend that keeps it longer, and reading does not extend it', function () {
    $now = 1_000_000;
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.', static function () use (&$now): int {
        return $now;
    });

    $cache->set('svc', new IcapResponse(200), 10);
    $now += 9;
    expect($cache->get('svc'))->not->toBeNull();
    expect($cache->get('svc'))->not->toBeNull();
    $now += 1;
    expect($cache->get('svc'))->toBeNull();

    // An entry from the future is none: the clock went back, or the writer's clock is ahead.
    $cache->set('later', new IcapResponse(200), 10);
    $now -= 1;
    expect($cache->get('later'))->toBeNull();
});

/*
 * an item of the backend is the entry and nothing else, with a finite lifetime of at most an hour: no list of keys
 * that grows with them.
 */
it('keeps one self-contained item per entry, each with a finite lifetime of at most an hour', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    foreach (['a' => 30, 'b' => 3600, 'c' => 3601, 'd' => PHP_INT_MAX] as $key => $ttl) {
        $cache->set($key, new IcapResponse(200), $ttl, '"t"');
    }

    expect(count($inner->all()))->toBe(4);
    expect(array_values($inner->ttls))->toBe([30, 3600, 3600, 3600]);

    $cache->delete('a');
    expect(count($inner->all()))->toBe(3);
});

it('keeps an entry for the shortest of the TTL it is given, the Options-TTL of the answer and an hour', function () {
    foreach (StoredEntryCases::lifetimes() as $case => [$fields, $ttl, $lifetime]) {
        $inner = new ArraySimpleCache();
        $cache = new Psr16OptionsCache($inner, 'icap.');

        $cache->set('svc', new IcapResponse(200, $fields), $ttl);

        // The backend is told the time, and the entry carries it for a reader that is not.
        $entry = array_values($inner->all())[0] ?? null;
        expect(array_values($inner->ttls))->toBe([$lifetime], $case);
        expect($entry)->toBeArray();
        /** @var array{stored: int, expires: int} $entry */
        expect($entry['expires'] - $entry['stored'])->toBe($lifetime, $case);
    }
});

it('stores nothing it would not hand out again', function () {
    foreach (StoredEntryCases::notKept() as $case => $response) {
        $inner = new ArraySimpleCache();
        $cache = new Psr16OptionsCache($inner, 'icap.');

        $cache->set('svc', $response, 60);

        expect($inner->all())->toBe([], $case);
        expect($cache->get('svc'))->toBeNull($case);
    }
});

/*
 * a tag belongs to its service. Writers that interleave, and a backend that drops an item, cannot invalidate the
 * entry of another service.
 */
it('keeps every entry when two writers interleave and a service reports a new tag', function () {
    $inner = new ArraySimpleCache();
    $a = new Psr16OptionsCache($inner, 'icap.');
    $b = new Psr16OptionsCache($inner, 'icap.');

    // B stores its entry while A is in the middle of storing its own.
    $inner->beforeSet = static function () use ($b): void {
        $b->set('b', new IcapResponse(200, ['Service' => ['b']]), 60, '"old"');
    };
    $a->set('a', new IcapResponse(200, ['Service' => ['a']]), 60, '"old"');
    $a->set('c', new IcapResponse(200, ['Service' => ['c']]), 60, '"new"');

    foreach (['a', 'b', 'c'] as $key) {
        expect($a->get($key)?->headers['Service'])->toBe([$key], $key);
        expect($b->get($key)?->headers['Service'])->toBe([$key], $key);
    }
});

it('answers a miss for an item the backend dropped, and affects no other entry', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

    $cache->set('a', new IcapResponse(200, ['Service' => ['a']]), 60, '"v1"');
    $slotOfA = (string) array_key_first($inner->all());
    $cache->set('b', new IcapResponse(200, ['Service' => ['b']]), 60, '"v1"');

    $inner->delete($slotOfA);
    expect($cache->get('a'))->toBeNull();
    expect($cache->get('b')?->headers['Service'])->toBe(['b']);

    $cache->set('a', new IcapResponse(200, ['Service' => ['a again']]), 60, '"v2"');
    expect($cache->get('a')?->headers['Service'])->toBe(['a again']);
    expect($cache->get('b')?->headers['Service'])->toBe(['b']);
});

it('stores an OPTIONS response and finds its preview size under one key of a strict backend', function () {
    $hosts = ['icap.example', '2001:db8::1'];
    $services = ['/avscan', '/avscan?profile=a/b:c'];
    $options = "ICAP/1.0 200 OK\r\nOptions-TTL: 60\r\nPreview: 4096\r\nISTag: \"v1\"\r\nEncapsulated: null-body=0\r\n\r\n";
    $clean = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    try {
        foreach ($hosts as $host) {
            foreach ($services as $service) {
                $transport = new ScriptedTransport([$options, $clean]);
                $client = new IcapClient(
                    new Config($host),
                    $transport,
                    new RequestFormatter(),
                    new ResponseParser(),
                    optionsCache: new Psr16OptionsCache(new ArraySimpleCache(), 'icap.'),
                );
                $path = ScriptedSource::add('preview', 'payload');

                /** @var AsyncTestCase $this */
                $this->runAsyncTest(function () use ($client, $service, $path) {
                    $client->options($service)->await();
                    $client->scanFileWithPreview($service, $path)->await();
                });

                // The preview size of the request is the one the stored OPTIONS response advertised.
                expect(count($transport->requests))->toBe(2, "$host $service");
                expect($transport->requests[1])->toContain("Preview: 4096\r\n");
            }
        }
    } finally {
        ScriptedSource::reset();
    }
});

it('reports a failing backend as IcapStorageException with its cause and leaves other errors alone', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');
    $calls = [
        'get'    => fn () => $cache->get('k'),
        'set'    => fn () => $cache->set('k', new IcapResponse(200), 60, '"v1"'),
        'delete' => fn () => $cache->delete('k'),
    ];
    $backend = new SimpleCacheFailure('The backend is down.');
    $bug = new TypeError('A bug in the backend.');

    foreach ($calls as $call => $run) {
        $inner->failure = $backend;
        $caught = null;
        try {
            $run();
        } catch (Throwable $e) {
            $caught = $e;
        }
        expect($caught)->toBeInstanceOf(IcapStorageException::class, $call);
        expect($caught?->getPrevious())->toBe($backend, $call);

        $inner->failure = $bug;
        expect($run)->toThrow(TypeError::class, $bug->getMessage(), $call);
    }
});

// --- In-memory PSR-16 implementation for testing ---

final class SimpleCacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}

final class SimpleCacheFailure extends \RuntimeException implements \Psr\SimpleCache\CacheException
{
}

/**
 * Minimal PSR-16 implementation backed by an array. TTL is ignored
 * (entries never expire) — sufficient for unit tests; the TTL of each set()
 * is recorded. Like a strict production backend it refuses a key that PSR-16
 * does not guarantee: other than A-Z a-z 0-9 _ . or longer than 64 characters.
 */
final class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, true> every key a call carried; each of them passed the key check */
    public array $seen = [];

    /** @var array<string, int|\DateInterval|null> the TTL of the last set() of each key */
    public array $ttls = [];

    /** Thrown by every call that carries a key, while set. */
    public ?\Throwable $failure = null;

    /** Runs once, inside the next set(), before the value is stored: a writer that gets in between. */
    public ?\Closure $beforeSet = null;

    /**
     * @return array<string, mixed> every item the backend holds
     */
    public function all(): array
    {
        return $this->store;
    }

    private function valid(string $key): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (preg_match('/^[A-Za-z0-9_.]{1,64}\z/', $key) !== 1) {
            throw new SimpleCacheKeyException('Not a valid PSR-16 key: ' . var_export(substr($key, 0, 80), true));
        }
        $this->seen[$key] = true;

        return $key;
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$this->valid($key)] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->valid($key);
        if ($this->beforeSet !== null) {
            $hook = $this->beforeSet;
            $this->beforeSet = null;
            $hook();
        }
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->store[$this->valid($key)]);
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->store[$this->valid($key)] ?? $default;
        }
        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->store[$this->valid($key)] = $value;
        }
        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$this->valid($key)]);
        }
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($this->valid($key), $this->store);
    }
}

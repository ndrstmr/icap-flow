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

use Ndrstmr\Icap\Cache\Psr6OptionsCache;
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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

uses(AsyncTestCase::class);

it('delegates get to PSR-6 and reconstructs IcapResponse', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $response = new IcapResponse(200, ['Preview' => ['1024']]);
    $cache->set('host:1344/svc', $response, 60);

    $result = $cache->get('host:1344/svc');
    expect($result)->not->toBeNull()
        ->and($result?->statusCode)->toBe(200)
        ->and($result?->headers['Preview'])->toBe(['1024']);
});

it('returns null on cache miss', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    expect($cache->get('nonexistent'))->toBeNull();
});

it('delegates delete to PSR-6', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('k', new IcapResponse(200), 60);
    expect($cache->get('k'))->not->toBeNull();

    $cache->delete('k');
    expect($cache->get('k'))->toBeNull();
});

it('keeps the entries of the other services when a service reports a new ISTag', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

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
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('k', new IcapResponse(200), 0);
    expect($cache->get('k'))->toBeNull();

    $cache->set('k', new IcapResponse(200), -5);
    expect($cache->get('k'))->toBeNull();
});

/*
 * M12: PSR-6 keys are A-Z a-z 0-9 _ . up to 64 characters, and {}()/\@: are
 * reserved. The pool below enforces that on every call, so an adapter that
 * hands a backend the caller's key, the prefix or a meta key unchanged fails.
 */
it('hands the pool only keys that follow the PSR-6 rules, whatever key the caller uses', function () {
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
        $pool = new ArrayCacheItemPool();
        $cache = new Psr6OptionsCache($pool, 'icap.');

        $cache->set($key, new IcapResponse(200, ['Preview' => ['1024']], 'body'), 60, '"istag-1"');
        expect($cache->get($key)?->statusCode)->toBe(200, $case);

        $cache->delete($key);
        expect($cache->get($key))->toBeNull($case);

        // The entry alone, under a key of 64 hex digits: no ISTag item, no list of keys.
        expect(count($pool->seen))->toBe(1, $case);
        foreach (array_keys($pool->seen) as $backendKey) {
            expect(preg_match('/^[0-9a-f]{64}\z/', (string) $backendKey))->toBe(1, "$case: $backendKey");
        }
    }
});

it('keeps the entries of two prefixes apart even when prefix and key add up to the same text', function () {
    $pool = new ArrayCacheItemPool();
    $a = new Psr6OptionsCache($pool, 'a');
    $ab = new Psr6OptionsCache($pool, 'ab');

    $a->set('bc', new IcapResponse(200, ['Service' => ['a']]), 60, '"v1"');
    // 'a' + 'bc' is 'ab' + 'c'.
    expect($ab->get('c'))->toBeNull();

    $ab->set('c', new IcapResponse(200, ['Service' => ['ab']]), 60, '"v1"');
    expect($a->get('bc')?->headers['Service'])->toBe(['a']);
    expect($ab->get('c')?->headers['Service'])->toBe(['ab']);

    // The same prefix finds the same entries from another adapter.
    expect((new Psr6OptionsCache($pool, 'ab'))->get('c')?->headers['Service'])->toBe(['ab']);
});

it('treats a caller key that was the name of a former meta entry like any other key', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool);

    foreach (['x', '__icap_istag', '__icap_keys', 'y'] as $name) {
        $cache->set($name, new IcapResponse(200, ['Service' => [$name]]), 60, '"v1"');
    }

    foreach (['x', '__icap_istag', '__icap_keys', 'y'] as $name) {
        expect($cache->get($name)?->headers['Service'])->toBe([$name], $name);
    }
    expect(count($pool->items()))->toBe(4);
});

it('does not read entries of the old key schemes and leaves them and foreign entries in the pool', function () {
    $pool = new ArrayCacheItemPool();
    $legacy = ['statusCode' => 200, 'headers' => ['Preview' => ['1']], 'body' => ''];
    // What an earlier version stored with the prefix "icap.": the entry, its ISTag and the tracked keys.
    foreach (['icap.svc' => $legacy, 'icap.__icap_istag' => '"old"', 'icap.__icap_keys' => ['svc'], 'app.session' => 'not ours'] as $key => $value) {
        $pool->save($pool->getItem($key)->set($value));
    }

    $cache = new Psr6OptionsCache($pool, 'icap.');
    expect($cache->get('svc'))->toBeNull();

    $cache->set('svc', new IcapResponse(200, ['Service' => ['new']]), 60, '"new"');
    expect($cache->get('svc')?->headers['Service'])->toBe(['new']);

    expect($pool->getItem('icap.svc')->get())->toBe($legacy);
    expect($pool->getItem('icap.__icap_istag')->get())->toBe('"old"');
    expect($pool->getItem('icap.__icap_keys')->get())->toBe(['svc']);
    expect($pool->getItem('app.session')->get())->toBe('not ours');
});

/*
 * what a shared pool returns is not trusted. A value that is not a whole, current entry for this key is a miss;
 * it neither raises a TypeError nor becomes a response.
 */
it('answers a miss for every stored value that is no entry for this key at this time', function () {
    $now = 1_000_000;
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.', static function () use (&$now): int {
        return $now;
    });

    $cache->set('svc', new IcapResponse(200, ['Preview' => ['1024'], 'ISTag' => ['"v1"']], 'opt-body'), 60, '"v1"');
    $slot = (string) array_key_first($pool->items());
    $entry = $pool->getItem($slot)->get();
    expect($entry)->toBeArray();
    /** @var array<string, mixed> $entry */
    expect($cache->get('svc')?->body)->toBe('opt-body');

    foreach (StoredEntryCases::unusable($entry, $now) as $case => $value) {
        $pool->save($pool->getItem($slot)->set($value));
        expect($cache->get('svc'))->toBeNull($case);
    }

    // The same slot holds the whole entry again, and so do the entries that only look like the ones above.
    $pool->save($pool->getItem($slot)->set($entry));
    expect($cache->get('svc')?->headers['Preview'])->toBe(['1024']);

    foreach (StoredEntryCases::usable($entry, $now) as $case => $value) {
        $pool->save($pool->getItem($slot)->set($value));
        expect($cache->get('svc'))->not->toBeNull($case);
    }
});

it('answers a miss for an entry that another key wrote to this slot', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('a', new IcapResponse(200, ['Service' => ['a']]), 60);
    $slotOfA = (string) array_key_first($pool->items());
    $cache->set('b', new IcapResponse(200, ['Service' => ['b']]), 60);
    $slotOfB = (string) array_key_last($pool->items());

    $pool->save($pool->getItem($slotOfB)->set($pool->getItem($slotOfA)->get()));

    expect($cache->get('b'))->toBeNull();
    expect($cache->get('a')?->headers['Service'])->toBe(['a']);
});

it('ends an entry at its own end, also in a pool that keeps it longer, and reading does not extend it', function () {
    $now = 1_000_000;
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.', static function () use (&$now): int {
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
 * an item of the pool is the entry and nothing else, with a finite lifetime of at most an hour: no list of keys
 * that grows with them.
 */
it('keeps one self-contained item per entry, each with a finite lifetime of at most an hour', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    foreach (['a' => 30, 'b' => 3600, 'c' => 3601, 'd' => PHP_INT_MAX] as $key => $ttl) {
        $cache->set($key, new IcapResponse(200), $ttl, '"t"');
    }

    expect(count($pool->items()))->toBe(4);
    expect(array_values(array_map(static fn (ArrayCacheItem $item): ?int => $item->getTtl(), $pool->items())))->toBe([30, 3600, 3600, 3600]);

    $cache->delete('a');
    expect(count($pool->items()))->toBe(3);
});

it('keeps an entry for the shortest of the TTL it is given, the Options-TTL of the answer and an hour', function () {
    foreach (StoredEntryCases::lifetimes() as $case => [$fields, $ttl, $lifetime]) {
        $pool = new ArrayCacheItemPool();
        $cache = new Psr6OptionsCache($pool, 'icap.');

        $cache->set('svc', new IcapResponse(200, $fields), $ttl);

        // The pool is told the time, and the entry carries it for a reader that is not.
        $item = array_values($pool->items())[0] ?? null;
        $entry = $item?->get();
        expect($item?->getTtl())->toBe($lifetime, $case);
        expect($entry)->toBeArray();
        /** @var array{stored: int, expires: int} $entry */
        expect($entry['expires'] - $entry['stored'])->toBe($lifetime, $case);
    }
});

it('stores nothing it would not hand out again', function () {
    foreach (StoredEntryCases::notKept() as $case => $response) {
        $pool = new ArrayCacheItemPool();
        $cache = new Psr6OptionsCache($pool, 'icap.');

        $cache->set('svc', $response, 60);

        expect($pool->items())->toBe([], $case);
        expect($cache->get('svc'))->toBeNull($case);
    }
});

/*
 * a tag belongs to its service. Writers that interleave, and a pool that drops an item, cannot invalidate the
 * entry of another service.
 */
it('keeps every entry when two writers interleave and a service reports a new tag', function () {
    $pool = new ArrayCacheItemPool();
    $a = new Psr6OptionsCache($pool, 'icap.');
    $b = new Psr6OptionsCache($pool, 'icap.');

    // B stores its entry while A is in the middle of storing its own.
    $pool->beforeSave = static function () use ($b): void {
        $b->set('b', new IcapResponse(200, ['Service' => ['b']]), 60, '"old"');
    };
    $a->set('a', new IcapResponse(200, ['Service' => ['a']]), 60, '"old"');
    $a->set('c', new IcapResponse(200, ['Service' => ['c']]), 60, '"new"');

    foreach (['a', 'b', 'c'] as $key) {
        expect($a->get($key)?->headers['Service'])->toBe([$key], $key);
        expect($b->get($key)?->headers['Service'])->toBe([$key], $key);
    }
});

it('answers a miss for an item the pool dropped, and affects no other entry', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('a', new IcapResponse(200, ['Service' => ['a']]), 60, '"v1"');
    $slotOfA = (string) array_key_first($pool->items());
    $cache->set('b', new IcapResponse(200, ['Service' => ['b']]), 60, '"v1"');

    $pool->deleteItem($slotOfA);
    expect($cache->get('a'))->toBeNull();
    expect($cache->get('b')?->headers['Service'])->toBe(['b']);

    $cache->set('a', new IcapResponse(200, ['Service' => ['a again']]), 60, '"v2"');
    expect($cache->get('a')?->headers['Service'])->toBe(['a again']);
    expect($cache->get('b')?->headers['Service'])->toBe(['b']);
});

it('stores an OPTIONS response and finds its preview size under one key of a strict pool', function () {
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
                    optionsCache: new Psr6OptionsCache(new ArrayCacheItemPool(), 'icap.'),
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

it('reports a failing pool as IcapStorageException with its cause and leaves other errors alone', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');
    $calls = [
        'get'    => fn () => $cache->get('k'),
        'set'    => fn () => $cache->set('k', new IcapResponse(200), 60, '"v1"'),
        'delete' => fn () => $cache->delete('k'),
    ];
    $backend = new CachePoolFailure('The pool is down.');
    $bug = new TypeError('A bug in the pool.');

    foreach ($calls as $call => $run) {
        $pool->failure = $backend;
        $caught = null;
        try {
            $run();
        } catch (Throwable $e) {
            $caught = $e;
        }
        expect($caught)->toBeInstanceOf(IcapStorageException::class, $call);
        expect($caught?->getPrevious())->toBe($backend, $call);

        $pool->failure = $bug;
        expect($run)->toThrow(TypeError::class, $bug->getMessage(), $call);
    }
});

// --- In-memory PSR-6 implementation for testing ---

final class CachePoolKeyException extends \InvalidArgumentException implements \Psr\Cache\InvalidArgumentException
{
}

final class CachePoolFailure extends \RuntimeException implements \Psr\Cache\CacheException
{
}

final class ArrayCacheItem implements CacheItemInterface
{
    private mixed $value = null;
    private bool $isHit;
    private ?int $ttl = null;

    public function __construct(
        private readonly string $key,
        mixed $value = null,
        bool $isHit = false,
    ) {
        $this->value = $value;
        $this->isHit = $isHit;
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->value;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->isHit;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;
        return $this;
    }

    #[\Override]
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    #[\Override]
    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time instanceof \DateInterval) {
            $this->ttl = (int) (new \DateTime())->add($time)->getTimestamp() - time();
        } else {
            $this->ttl = $time;
        }
        return $this;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }
}

/**
 * Like a strict production pool it refuses a key that PSR-6 does not
 * guarantee: other than A-Z a-z 0-9 _ . or longer than 64 characters.
 */
final class ArrayCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, ArrayCacheItem> */
    private array $items = [];

    /** @var list<ArrayCacheItem> */
    private array $deferred = [];

    /** @var array<string, true> every key a call carried; each of them passed the key check */
    public array $seen = [];

    /** Thrown by every call that carries a key, while set. */
    public ?\Throwable $failure = null;

    /** Runs once, inside the next save(), before the item is stored: a writer that gets in between. */
    public ?\Closure $beforeSave = null;

    /**
     * @return array<string, ArrayCacheItem> every item the pool holds
     */
    public function items(): array
    {
        return $this->items;
    }

    private function valid(string $key): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (preg_match('/^[A-Za-z0-9_.]{1,64}\z/', $key) !== 1) {
            throw new CachePoolKeyException('Not a valid PSR-6 key: ' . var_export(substr($key, 0, 80), true));
        }
        $this->seen[$key] = true;

        return $key;
    }

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        $this->valid($key);
        if (isset($this->items[$key])) {
            return new ArrayCacheItem($key, $this->items[$key]->get(), true);
        }
        return new ArrayCacheItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }
        return $result;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return isset($this->items[$this->valid($key)]);
    }

    #[\Override]
    public function clear(): bool
    {
        $this->items = [];
        $this->deferred = [];
        return true;
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        unset($this->items[$this->valid($key)]);
        return true;
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$this->valid($key)]);
        }
        return true;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        /** @var ArrayCacheItem $item */
        if ($this->beforeSave !== null) {
            $hook = $this->beforeSave;
            $this->beforeSave = null;
            $hook();
        }
        $this->items[$item->getKey()] = $item;
        return true;
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        /** @var ArrayCacheItem $item */
        $this->deferred[] = $item;
        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];
        return true;
    }
}

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

it('flushes all tracked entries when ISTag changes', function () {
    $pool = new ArrayCacheItemPool();
    $cache = new Psr6OptionsCache($pool, 'icap.');

    $cache->set('svc1', new IcapResponse(200), 60, 'istag-v1');
    $cache->set('svc2', new IcapResponse(204), 60, 'istag-v1');
    expect($cache->get('svc1'))->not->toBeNull();
    expect($cache->get('svc2'))->not->toBeNull();

    // New ISTag → both entries should be flushed.
    $cache->set('svc3', new IcapResponse(200), 60, 'istag-v2');
    expect($cache->get('svc1'))->toBeNull();
    expect($cache->get('svc2'))->toBeNull();
    expect($cache->get('svc3'))->not->toBeNull();
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

        // The entry, the ISTag and the list of tracked keys, each under a key of 64 hex digits.
        expect(count($pool->seen))->toBe(3, $case);
        foreach (array_keys($pool->seen) as $backendKey) {
            expect(preg_match('/^[0-9a-f]{64}\z/', (string) $backendKey))->toBe(1, "$case: $backendKey");
        }
    }
});

it('keeps the entries of two prefixes apart even when prefix and key add up to the same text', function () {
    $pool = new ArrayCacheItemPool();
    $a = new Psr6OptionsCache($pool, 'a');
    $ab = new Psr6OptionsCache($pool, 'ab');

    $a->set('bc', new IcapResponse(200), 60, '"v1"');
    // 'a' + 'bc' is 'ab' + 'c'.
    expect($ab->get('c'))->toBeNull();

    $ab->set('c', new IcapResponse(204), 60, '"v1"');
    expect($a->get('bc')?->statusCode)->toBe(200);
    expect($ab->get('c')?->statusCode)->toBe(204);

    // A new ISTag flushes the entries of its own prefix only.
    $a->set('x', new IcapResponse(200), 60, '"v2"');
    expect($a->get('bc'))->toBeNull();
    expect($ab->get('c')?->statusCode)->toBe(204);

    // The same prefix finds the same entries from another adapter.
    expect((new Psr6OptionsCache($pool, 'ab'))->get('c')?->statusCode)->toBe(204);
});

it('does not let a caller key overwrite the ISTag or the list of tracked keys', function () {
    foreach (['__icap_istag', '__icap_keys'] as $name) {
        $cache = new Psr6OptionsCache(new ArrayCacheItemPool());

        $cache->set('x', new IcapResponse(200), 60, '"v1"');
        $cache->set($name, new IcapResponse(204), 60);
        // The same ISTag again: nothing may be flushed.
        $cache->set('y', new IcapResponse(200), 60, '"v1"');

        expect($cache->get('x')?->statusCode)->toBe(200, $name);
        expect($cache->get($name)?->statusCode)->toBe(204, $name);
        expect($cache->get('y')?->statusCode)->toBe(200, $name);
    }
});

it('does not read entries of the old key scheme and leaves them and foreign entries in the pool', function () {
    $pool = new ArrayCacheItemPool();
    $legacy = ['statusCode' => 200, 'headers' => ['Preview' => ['1']], 'body' => ''];
    // What an earlier version stored with the prefix "icap.": the entry, its ISTag and the tracked keys.
    foreach (['icap.svc' => $legacy, 'icap.__icap_istag' => '"old"', 'icap.__icap_keys' => ['svc'], 'app.session' => 'not ours'] as $key => $value) {
        $pool->save($pool->getItem($key)->set($value));
    }

    $cache = new Psr6OptionsCache($pool, 'icap.');
    expect($cache->get('svc'))->toBeNull();

    // A different ISTag than the old one would flush the entries the adapter knows, and only those.
    $cache->set('svc', new IcapResponse(204), 60, '"new"');
    expect($cache->get('svc')?->statusCode)->toBe(204);

    expect($pool->getItem('icap.svc')->get())->toBe($legacy);
    expect($pool->getItem('icap.__icap_istag')->get())->toBe('"old"');
    expect($pool->getItem('icap.__icap_keys')->get())->toBe(['svc']);
    expect($pool->getItem('app.session')->get())->toBe('not ours');
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

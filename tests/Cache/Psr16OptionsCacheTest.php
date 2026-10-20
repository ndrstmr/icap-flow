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
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Tests\AsyncTestCase;
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

it('flushes all entries when ISTag changes', function () {
    $inner = new ArraySimpleCache();
    $cache = new Psr16OptionsCache($inner, 'icap.');

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

        // The entry, the ISTag and the list of tracked keys, each under a key of 64 hex digits.
        expect(count($inner->seen))->toBe(3, $case);
        foreach (array_keys($inner->seen) as $backendKey) {
            expect(preg_match('/^[0-9a-f]{64}\z/', (string) $backendKey))->toBe(1, "$case: $backendKey");
        }
    }
});

it('keeps the entries of two prefixes apart even when prefix and key add up to the same text', function () {
    $inner = new ArraySimpleCache();
    $a = new Psr16OptionsCache($inner, 'a');
    $ab = new Psr16OptionsCache($inner, 'ab');

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
    expect((new Psr16OptionsCache($inner, 'ab'))->get('c')?->statusCode)->toBe(204);
});

it('does not let a caller key overwrite the ISTag or the list of tracked keys', function () {
    foreach (['__icap_istag', '__icap_keys'] as $name) {
        $cache = new Psr16OptionsCache(new ArraySimpleCache());

        $cache->set('x', new IcapResponse(200), 60, '"v1"');
        $cache->set($name, new IcapResponse(204), 60);
        // The same ISTag again: nothing may be flushed.
        $cache->set('y', new IcapResponse(200), 60, '"v1"');

        expect($cache->get('x')?->statusCode)->toBe(200, $name);
        expect($cache->get($name)?->statusCode)->toBe(204, $name);
        expect($cache->get('y')?->statusCode)->toBe(200, $name);
    }
});

it('does not read entries of the old key scheme and leaves them and foreign entries in the backend', function () {
    $inner = new ArraySimpleCache();
    $legacy = ['statusCode' => 200, 'headers' => ['Preview' => ['1']], 'body' => ''];
    // What an earlier version stored with the prefix "icap.": the entry, its ISTag and the tracked keys.
    $inner->set('icap.svc', $legacy, 60);
    $inner->set('icap.__icap_istag', '"old"');
    $inner->set('icap.__icap_keys', ['svc']);
    $inner->set('app.session', 'not ours');

    $cache = new Psr16OptionsCache($inner, 'icap.');
    expect($cache->get('svc'))->toBeNull();

    // A different ISTag than the old one would flush the entries the adapter knows, and only those.
    $cache->set('svc', new IcapResponse(204), 60, '"new"');
    expect($cache->get('svc')?->statusCode)->toBe(204);

    expect($inner->get('icap.svc'))->toBe($legacy);
    expect($inner->get('icap.__icap_istag'))->toBe('"old"');
    expect($inner->get('icap.__icap_keys'))->toBe(['svc']);
    expect($inner->get('app.session'))->toBe('not ours');
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

// --- In-memory PSR-16 implementation for testing ---

final class SimpleCacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}

/**
 * Minimal PSR-16 implementation backed by an array. TTL is ignored
 * (entries never expire) — sufficient for unit tests. Like a strict
 * production backend it refuses a key that PSR-16 does not guarantee: other
 * than A-Z a-z 0-9 _ . or longer than 64 characters.
 */
final class ArraySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, true> every key a call carried; each of them passed the key check */
    public array $seen = [];

    private function valid(string $key): string
    {
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
        $this->store[$this->valid($key)] = $value;
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

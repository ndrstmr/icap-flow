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

namespace Ndrstmr\Icap\Tests\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache on a directory, so that two processes that open the same directory share it. Like a strict backend it
 * refuses a key other than A-Z a-z 0-9 _ . of up to 64 characters, and it ends an item by the clock when it is read.
 */
final class FileSimpleCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        $raw = is_file($file) ? file_get_contents($file) : false;
        $item = $raw === false ? null : unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($item) || ($item[0] !== null && $item[0] <= time())) {
            return $default;
        }

        return $item[1];
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $until = match (true) {
            $ttl === null               => null,
            $ttl instanceof \DateInterval => (new \DateTimeImmutable())->add($ttl)->getTimestamp(),
            default                     => time() + $ttl,
        };
        $file = $this->file($key);
        // Written beside the item and moved over it, so that a reader sees the old item or the new one.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        return file_put_contents($temporary, serialize([$until, $value])) !== false && rename($temporary, $file);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $file = $this->file($key);
        if (is_file($file)) {
            unlink($file);
        }

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        foreach (glob($this->directory . '/*.item') ?: [] as $file) {
            unlink($file);
        }

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
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
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private function file(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_.]{1,64}\z/', $key) !== 1) {
            throw new \InvalidArgumentException('Not a valid PSR-16 key.');
        }

        return $this->directory . '/' . $key . '.item';
    }
}

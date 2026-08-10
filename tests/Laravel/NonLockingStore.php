<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Contracts\Cache\Store;

/** A cache store with no atomic locks — the `file` driver's shape. */
final class NonLockingStore implements Store
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    public function many(array $keys)
    {
        return array_combine($keys, array_map($this->get(...), $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key] = ((int) ($this->items[$key] ?? 0)) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function touch($key, $ttl)
    {
        return isset($this->items[$key]);
    }

    public function getPrefix()
    {
        return '';
    }
}

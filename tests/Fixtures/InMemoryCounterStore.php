<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterValue;

final class InMemoryCounterStore implements AtomicCounterStoreInterface
{
    /** @var array<string, int> */
    private array $values = [];

    public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, -$by, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        $exists = isset($this->values[$key]);
        unset($this->values[$key]);

        return $exists;
    }

    public function get(string $key): ?int
    {
        return $this->values[$key] ?? null;
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, $by, $ttlSeconds);
    }

    private function change(string $key, int $by, ?int $ttlSeconds): AtomicCounterValue
    {
        $initialized = !isset($this->values[$key]);
        $this->values[$key] = ($this->values[$key] ?? 0) + $by;
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            unset($this->values[$key]);
        }

        return new AtomicCounterValue($this->values[$key] ?? 0, $initialized);
    }
}

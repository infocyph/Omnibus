<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterValue;
use Psr\Clock\ClockInterface;

final class InMemoryCounterStore implements AtomicCounterStoreInterface
{
    /** @var array<string, int> */
    private array $values = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, -$by, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        self::assertKey($key);
        $this->expire($key);
        $exists = isset($this->values[$key]);
        unset($this->values[$key], $this->expiresAt[$key]);

        return $exists;
    }

    public function get(string $key): ?int
    {
        self::assertKey($key);
        $this->expire($key);

        return $this->values[$key] ?? null;
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        return $this->change($key, $by, $ttlSeconds);
    }

    private function change(string $key, int $by, ?int $ttlSeconds): AtomicCounterValue
    {
        self::assertKey($key);
        $this->expire($key);
        $initialized = !isset($this->values[$key]);
        $this->values[$key] = ($this->values[$key] ?? 0) + $by;
        if ($ttlSeconds !== null) {
            if ($ttlSeconds < 1) {
                unset($this->values[$key], $this->expiresAt[$key]);
            } elseif ($this->clock instanceof ClockInterface) {
                $this->expiresAt[$key] = (int) $this->clock->now()->format('U') + $ttlSeconds;
            }
        }

        return new AtomicCounterValue($this->values[$key] ?? 0, $initialized);
    }

    private function expire(string $key): void
    {
        if (
            $this->clock instanceof ClockInterface
            && isset($this->expiresAt[$key])
            && $this->expiresAt[$key] <= (int) $this->clock->now()->format('U')
        ) {
            unset($this->values[$key], $this->expiresAt[$key]);
        }
    }

    private static function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Counter key is not backend-safe.');
        }
    }
}

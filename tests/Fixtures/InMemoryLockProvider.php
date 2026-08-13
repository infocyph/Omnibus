<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseProvider;
use Psr\Clock\ClockInterface;

final class InMemoryLockProvider implements DetachedLeaseProvider
{
    /** @var array<string, string> */
    private array $locks = [];

    /** @var array<string, float> */
    private array $expiresAt = [];

    private int $acquisitions = 0;

    public ?int $failAcquireAt = null;

    public bool $refreshable = true;

    public bool $releaseFails = false;

    public ?float $lastRefreshedLease = null;

    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        $this->acquisitions++;
        if ($this->failAcquireAt === $this->acquisitions) {
            return null;
        }
        $this->expire($key);
        if (isset($this->locks[$key])) {
            return null;
        }
        $token = hash('sha256', $key.(string) $waitSeconds.(string) $leaseSeconds);
        $this->locks[$key] = $token;
        if ($this->clock instanceof ClockInterface) {
            $this->expiresAt[$key] = $this->now() + $leaseSeconds;
        }

        return new LockHandle($key, $token, leaseSeconds: $leaseSeconds);
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        $this->lastRefreshedLease = $leaseSeconds;

        if ($handle instanceof LockHandle) {
            $this->expire($handle->key);
        }

        $refreshed = $this->refreshable
            && $handle instanceof LockHandle
            && $leaseSeconds > 0.0
            && ($this->locks[$handle->key] ?? null) === $handle->token;
        if ($refreshed && $this->clock instanceof ClockInterface) {
            $this->expiresAt[$handle->key] = $this->now() + $leaseSeconds;
        }

        return $refreshed;
    }

    public function release(?LockHandle $handle): void
    {
        if ($this->releaseFails) {
            throw new \RuntimeException('Lease cleanup unavailable.');
        }
        if (
            $handle instanceof LockHandle
            && ($this->locks[$handle->key] ?? null) === $handle->token
        ) {
            unset($this->locks[$handle->key], $this->expiresAt[$handle->key]);
        }
    }

    private function expire(string $key): void
    {
        if (
            $this->clock instanceof ClockInterface
            && isset($this->expiresAt[$key])
            && $this->expiresAt[$key] <= $this->now()
        ) {
            unset($this->locks[$key], $this->expiresAt[$key]);
        }
    }

    private function now(): float
    {
        return (float) $this->clock?->now()->format('U.u');
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseProvider;

final class InMemoryLockProvider implements DetachedLeaseProvider
{
    /** @var array<string, string> */
    private array $locks = [];

    public bool $refreshable = true;

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        if (isset($this->locks[$key])) {
            return null;
        }
        $token = hash('sha256', $key.(string) $waitSeconds.(string) $leaseSeconds);
        $this->locks[$key] = $token;

        return new LockHandle($key, $token, leaseSeconds: $leaseSeconds);
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        return $this->refreshable
            && $handle instanceof LockHandle
            && $leaseSeconds > 0.0
            && ($this->locks[$handle->key] ?? null) === $handle->token;
    }

    public function release(?LockHandle $handle): void
    {
        if (
            $handle instanceof LockHandle
            && ($this->locks[$handle->key] ?? null) === $handle->token
        ) {
            unset($this->locks[$handle->key]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;

final readonly class DetachedLeaseAdapter implements DetachedLeaseProvider
{
    public function __construct(private LockProviderInterface $inner) {}

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        return $this->inner->acquire($key, $waitSeconds, $leaseSeconds);
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        return $this->inner->refresh($handle, $leaseSeconds);
    }

    public function release(?LockHandle $handle): void
    {
        $this->inner->release($handle);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Psr\Clock\ClockInterface;

final readonly class CircuitBreakerScope implements ExecutionScope
{
    /** @var \Closure(Envelope):string */
    private \Closure $key;

    /** @param callable(Envelope):string $key */
    public function __construct(
        private ExecutionScope $inner,
        private AtomicCounterStoreInterface $counters,
        private LockProviderInterface $locks,
        private ClockInterface $clock,
        callable $key,
        private int $failureThreshold = 5,
        private int $recoverySeconds = 30,
        private int $failureWindowSeconds = 60,
        private int $probeLeaseSeconds = 300,
    ) {
        if (
            $failureThreshold < 1
            || $recoverySeconds < 1
            || $failureWindowSeconds < 1
            || $probeLeaseSeconds < 1
        ) {
            throw new \InvalidArgumentException('Circuit-breaker threshold and windows must be positive.');
        }
        $this->key = \Closure::fromCallable($key);
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $key = PolicyKey::storage('circuit', ($this->key)($envelope));
        $probe = $this->assertExecutionAllowed($key);

        try {
            $result = $this->inner->run($envelope, $handler);
        } catch (\Throwable $failure) {
            try {
                $this->recordFailure($key, $probe instanceof LockHandle);
            } catch (\Throwable) {
            }
            $this->releaseProbe($probe);

            throw $failure;
        }

        try {
            $this->withLock($key, function () use ($key): void {
                $this->counters->delete($this->failureKey($key));
                $this->counters->delete($this->openKey($key));
            });
        } catch (\Throwable) {
        }
        $this->releaseProbe($probe);

        return $result;
    }

    private function assertExecutionAllowed(string $key): ?LockHandle
    {
        $probe = null;
        $this->withLock($key, function () use ($key, &$probe): void {
            $openedAt = $this->counters->get($this->openKey($key));
            if ($openedAt === null) {
                return;
            }
            $now = (int) $this->clock->now()->format('U');
            if ($openedAt + $this->recoverySeconds > $now) {
                throw new CircuitOpen(sprintf('Circuit "%s" is open.', $key));
            }

            $probe = $this->locks->acquire(
                $this->probeKey($key),
                0.0,
                (float) $this->probeLeaseSeconds,
            );
            if (!$probe instanceof LockHandle) {
                throw new CircuitOpen(sprintf('Circuit "%s" recovery probe is active.', $key));
            }
        });

        return $probe;
    }

    private function failureKey(string $key): string
    {
        return $key . '.failures';
    }

    private function openKey(string $key): string
    {
        return $key . '.open';
    }

    private function probeKey(string $key): string
    {
        return $key . '.probe';
    }

    private function recordFailure(string $key, bool $probe): void
    {
        $this->withLock($key, function () use ($key, $probe): void {
            $failures = $this->counters->increment(
                $this->failureKey($key),
                ttlSeconds: $this->failureWindowSeconds,
            );
            if (!$probe && $failures->value < $this->failureThreshold) {
                return;
            }

            $this->counters->delete($this->openKey($key));
            $this->counters->increment(
                $this->openKey($key),
                (int) $this->clock->now()->format('U'),
                $this->recoverySeconds + (2 * $this->probeLeaseSeconds),
            );
        });
    }

    private function releaseProbe(?LockHandle $probe): void
    {
        if (!$probe instanceof LockHandle) {
            return;
        }

        try {
            $this->locks->release($probe);
        } catch (\Throwable) {
        }
    }

    /** @param callable():void $operation */
    private function withLock(string $key, callable $operation): void
    {
        $handle = $this->locks->acquire($key . '.lock', 0.0, 5.0);
        if ($handle === null) {
            throw new CircuitOpen(sprintf('Circuit "%s" state is being updated.', $key));
        }

        try {
            $operation();
        } finally {
            $this->locks->release($handle);
        }
    }
}

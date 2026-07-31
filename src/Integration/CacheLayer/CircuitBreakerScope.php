<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

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
    ) {
        if ($failureThreshold < 1 || $recoverySeconds < 1 || $failureWindowSeconds < 1) {
            throw new \InvalidArgumentException('Circuit-breaker threshold and windows must be positive.');
        }
        $this->key = \Closure::fromCallable($key);
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $key = PolicyKey::storage('circuit', ($this->key)($envelope));
        $this->assertClosed($key);

        try {
            $result = $this->inner->run($envelope, $handler);
        } catch (\Throwable $failure) {
            $this->recordFailure($key);

            throw $failure;
        }

        $this->withLock($key, function () use ($key): void {
            $this->counters->delete($this->failureKey($key));
            $this->counters->delete($this->openKey($key));
        });

        return $result;
    }

    private function assertClosed(string $key): void
    {
        $this->withLock($key, function () use ($key): void {
            $openedAt = $this->counters->get($this->openKey($key));
            if ($openedAt === null) {
                return;
            }
            $now = (int) $this->clock->now()->format('U');
            if ($openedAt + $this->recoverySeconds > $now) {
                throw new CircuitOpen(sprintf('Circuit "%s" is open.', $key));
            }

            $this->counters->delete($this->openKey($key));
            $this->counters->delete($this->failureKey($key));
        });
    }

    private function failureKey(string $key): string
    {
        return $key . '.failures';
    }

    private function openKey(string $key): string
    {
        return $key . '.open';
    }

    private function recordFailure(string $key): void
    {
        $this->withLock($key, function () use ($key): void {
            $failures = $this->counters->increment(
                $this->failureKey($key),
                ttlSeconds: $this->failureWindowSeconds,
            );
            if ($failures->value < $this->failureThreshold) {
                return;
            }

            $this->counters->delete($this->openKey($key));
            $this->counters->increment(
                $this->openKey($key),
                (int) $this->clock->now()->format('U'),
                $this->recoverySeconds,
            );
        });
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

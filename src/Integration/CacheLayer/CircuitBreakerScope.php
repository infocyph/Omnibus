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
        $this->key = $key(...);
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $key = PolicyKey::storage('circuit', ($this->key)($envelope));
        $admission = $this->assertExecutionAllowed($key);
        $probe = $admission['probe'];

        try {
            $result = $this->inner->run($envelope, $handler);
        } catch (\Throwable $failure) {
            try {
                $this->recordFailure($key, $probe instanceof LockHandle);
            } catch (\Throwable) {
            }
            $this->releaseProbeQuietly($probe);

            throw $failure;
        }

        try {
            $this->recordSuccess($key, $admission);
            $this->releaseProbe($probe);
        } catch (\Throwable $failure) {
            $this->releaseProbeQuietly($probe);

            throw new CoordinationCleanupFailedAfterExecution(
                sprintf('Circuit "%s" coordination cleanup failed after execution.', $key),
                previous: $failure,
            );
        }

        return $result;
    }

    /** @return array{generation:int,probe:LockHandle|null} */
    private function assertExecutionAllowed(string $key): array
    {
        $admission = ['generation' => 0, 'probe' => null];
        $this->withLock($key, function () use ($key, &$admission): void {
            $admission['generation'] = $this->generation($key);
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

            $admission['probe'] = $probe;
        });

        return $admission;
    }

    private function failureKey(string $key): string
    {
        return $key . '.failures';
    }

    private function generation(string $key): int
    {
        return $this->counters->get($this->generationKey($key)) ?? 0;
    }

    private function generationKey(string $key): string
    {
        return $key . '.generation';
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

            $this->counters->increment($this->generationKey($key));
            $this->counters->delete($this->openKey($key));
            $this->counters->increment(
                $this->openKey($key),
                (int) $this->clock->now()->format('U'),
                $this->recoverySeconds + (2 * $this->probeLeaseSeconds),
            );
        });
    }

    /** @param array{generation:int,probe:LockHandle|null} $admission */
    private function recordSuccess(string $key, array $admission): void
    {
        $this->withLock($key, function () use ($key, $admission): void {
            if ($this->generation($key) !== $admission['generation']) {
                return;
            }

            $probe = $admission['probe'];
            if ($probe instanceof LockHandle) {
                if (!$this->locks->refresh($probe, (float) $this->probeLeaseSeconds)) {
                    throw new \RuntimeException('Circuit recovery probe ownership expired before cleanup.');
                }

                $this->counters->delete($this->failureKey($key));
                $this->counters->delete($this->openKey($key));

                return;
            }

            if ($this->counters->get($this->openKey($key)) === null) {
                $this->counters->delete($this->failureKey($key));
            }
        });
    }

    private function releaseProbe(?LockHandle $probe): void
    {
        if ($probe instanceof LockHandle) {
            $this->locks->release($probe);
        }
    }

    private function releaseProbeQuietly(?LockHandle $probe): void
    {
        try {
            $this->releaseProbe($probe);
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
        } catch (\Throwable $failure) {
            try {
                $this->locks->release($handle);
            } catch (\Throwable) {
            }

            throw $failure;
        }

        $this->locks->release($handle);
    }
}

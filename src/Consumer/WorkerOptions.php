<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Transport\QueueName;

final readonly class WorkerOptions
{
    public function __construct(
        public string $queue = 'default',
        public int $prefetch = 1,
        public float $visibilitySeconds = 60.0,
        public float $idleSleepSeconds = 0.05,
        public float $maxIdleSleepSeconds = 1.0,
        public float $idleJitterRatio = 0.20,
        public ?int $maxMessages = null,
        public ?float $maxRuntimeSeconds = null,
        public ?int $memoryLimitBytes = null,
        public ?int $maxMemoryGrowthBytes = null,
        public bool $handleSignals = true,
    ) {
        QueueName::assert($queue);
        $this->validateReceiveOptions();
        $this->validateLifecycleOptions();
    }

    private function validateLifecycleOptions(): void
    {
        if ($this->maxMessages !== null && $this->maxMessages < 1) {
            throw new \InvalidArgumentException('Worker message limit must be at least 1 when configured.');
        }
        if (
            $this->maxRuntimeSeconds !== null
            && (!is_finite($this->maxRuntimeSeconds) || $this->maxRuntimeSeconds <= 0.0)
        ) {
            throw new \InvalidArgumentException('Worker runtime limit must be positive and finite when configured.');
        }
        if ($this->memoryLimitBytes !== null && $this->memoryLimitBytes < 1) {
            throw new \InvalidArgumentException('Worker memory limit must be at least 1 byte when configured.');
        }
        if ($this->maxMemoryGrowthBytes !== null && $this->maxMemoryGrowthBytes < 1) {
            throw new \InvalidArgumentException('Worker memory growth limit must be at least 1 byte when configured.');
        }
    }

    private function validateReceiveOptions(): void
    {
        if ($this->prefetch < 1 || $this->prefetch > 1_000) {
            throw new \InvalidArgumentException('Worker prefetch must be between 1 and 1000.');
        }
        if (!is_finite($this->visibilitySeconds) || $this->visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException('Worker visibility timeout must be positive and finite.');
        }
        if (!is_finite($this->idleSleepSeconds) || $this->idleSleepSeconds < 0.0) {
            throw new \InvalidArgumentException('Worker idle sleep must be finite and non-negative.');
        }
        if (!is_finite($this->maxIdleSleepSeconds) || $this->maxIdleSleepSeconds < $this->idleSleepSeconds) {
            throw new \InvalidArgumentException('Worker maximum idle sleep must be finite and at least the initial idle sleep.');
        }
        if (!is_finite($this->idleJitterRatio) || $this->idleJitterRatio < 0.0 || $this->idleJitterRatio > 1.0) {
            throw new \InvalidArgumentException('Worker idle jitter ratio must be between 0 and 1.');
        }
    }
}

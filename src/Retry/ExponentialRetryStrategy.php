<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Retry;

final readonly class ExponentialRetryStrategy implements RetryStrategy
{
    public function __construct(
        public int $maximumAttempts = 3,
        public float $initialDelaySeconds = 1.0,
        public float $multiplier = 2.0,
        public float $maximumDelaySeconds = 60.0,
        public float $jitterRatio = 0.0,
    ) {
        if (
            $maximumAttempts < 1
            || !is_finite($initialDelaySeconds)
            || $initialDelaySeconds < 0.0
            || !is_finite($multiplier)
            || $multiplier < 1.0
            || !is_finite($maximumDelaySeconds)
            || $maximumDelaySeconds < 0.0
            || !is_finite($jitterRatio)
            || $jitterRatio < 0.0
            || $jitterRatio > 1.0
        ) {
            throw new \InvalidArgumentException('Retry policy values are outside their supported bounds.');
        }
    }

    public function delaySeconds(int $attempt): float
    {
        $base = min(
            $this->maximumDelaySeconds,
            $this->initialDelaySeconds * ($this->multiplier ** max(0, $attempt - 1)),
        );
        if ($this->jitterRatio === 0.0 || $base === 0.0) {
            return $base;
        }

        $spread = $base * $this->jitterRatio;
        $offset = random_int(0, 1_000_000) / 1_000_000 * ($spread * 2.0) - $spread;

        return max(0.0, min($this->maximumDelaySeconds, $base + $offset));
    }

    public function shouldRetry(\Throwable $failure, int $attempt): bool
    {
        unset($failure);

        return $attempt < $this->maximumAttempts;
    }
}

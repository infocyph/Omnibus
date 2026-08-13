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
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Retry attempt must be positive.');
        }
        if ($this->initialDelaySeconds === 0.0 || $this->maximumDelaySeconds === 0.0) {
            return 0.0;
        }
        if ($this->multiplier === 1.0 || $this->initialDelaySeconds >= $this->maximumDelaySeconds) {
            $base = min($this->initialDelaySeconds, $this->maximumDelaySeconds);
        } else {
            $growthLog = ($attempt - 1) * log($this->multiplier);
            $limitLog = log($this->maximumDelaySeconds) - log($this->initialDelaySeconds);
            $base = $growthLog >= $limitLog
                ? $this->maximumDelaySeconds
                : min(
                    $this->maximumDelaySeconds,
                    exp(log($this->initialDelaySeconds) + $growthLog),
                );
        }
        if ($this->jitterRatio === 0.0 || $base === 0.0) {
            return $base;
        }

        $factor = random_int(-1_000_000, 1_000_000) / 1_000_000;
        $jittered = $base + ($base * $this->jitterRatio * $factor);

        return max(0.0, min($this->maximumDelaySeconds, $jittered));
    }

    public function shouldRetry(\Throwable $failure, int $attempt): bool
    {
        return !$failure instanceof NonRetryableFailure
            && $attempt < $this->maximumAttempts;
    }
}

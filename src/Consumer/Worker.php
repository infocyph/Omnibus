<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class Worker
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly Consumer $consumer,
        private readonly WorkerOptions $options = new WorkerOptions(),
    ) {}

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function run(): void
    {
        $this->registerSignals();
        $startedAt = hrtime(true);
        $processed = 0;
        $idleSleep = $this->options->idleSleepSeconds;

        while (!$this->shouldStop($startedAt, $processed)) {
            $result = $this->consumer->run(
                $this->options->queue,
                $this->options->prefetch,
                $this->options->visibilitySeconds,
            );
            $processed += $result->received;

            if ($result->received > 0) {
                $idleSleep = $this->options->idleSleepSeconds;

                continue;
            }

            if ($idleSleep > 0.0) {
                usleep((int) round($this->jittered($idleSleep) * 1_000_000));
            }
            $idleSleep = min(
                $this->options->maxIdleSleepSeconds,
                max($this->options->idleSleepSeconds, $idleSleep * 2.0),
            );
        }
    }

    private function jittered(float $seconds): float
    {
        $ratio = $this->options->idleJitterRatio;
        if ($ratio === 0.0 || $seconds === 0.0) {
            return $seconds;
        }

        $spread = $seconds * $ratio;
        $random = random_int(0, 1_000_000) / 1_000_000;

        return max(0.0, $seconds - $spread + (2.0 * $spread * $random));
    }

    private function registerSignals(): void
    {
        if (!$this->options->handleSignals || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn(): bool => $this->stopRequested = true);
        pcntl_signal(SIGINT, fn(): bool => $this->stopRequested = true);
    }

    private function shouldStop(int $startedAt, int $processed): bool
    {
        if ($this->stopRequested) {
            return true;
        }
        if ($this->options->maxMessages !== null && $processed >= $this->options->maxMessages) {
            return true;
        }
        if (
            $this->options->maxRuntimeSeconds !== null
            && (hrtime(true) - $startedAt) / 1_000_000_000 >= $this->options->maxRuntimeSeconds
        ) {
            return true;
        }

        return $this->options->memoryLimitBytes !== null
            && memory_get_usage(true) >= $this->options->memoryLimitBytes;
    }
}

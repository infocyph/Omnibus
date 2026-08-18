<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class Worker
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_TERMINATE = 15;

    /** @var array<int,callable|int> */
    private array $previousSignalHandlers = [];

    private ?bool $previousAsyncSignals = null;

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

        try {
            $startedAt = hrtime(true);
            $startedMemory = memory_get_usage(true);
            $processed = 0;
            $idleSleep = $this->options->idleSleepSeconds;

            while (!$this->shouldStop($startedAt, $startedMemory, $processed)) {
                $limit = $this->options->prefetch;
                if ($this->options->maxMessages !== null) {
                    $limit = min($limit, $this->options->maxMessages - $processed);
                }

                $result = $this->consumer->run(
                    $this->options->queue,
                    $limit,
                    $this->options->visibilitySeconds,
                );
                $processed += $result->received;

                if ($result->received > 0) {
                    $idleSleep = $this->options->idleSleepSeconds;

                    continue;
                }

                if ($idleSleep > 0.0) {
                    usleep((int) round($this->jittered($idleSleep) * 1_000_000));
                    $idleSleep = min($this->options->maxIdleSleepSeconds, $idleSleep * 2.0);
                }
            }
        } finally {
            $this->restoreSignals();
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
        if (
            !$this->options->handleSignals
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return;
        }

        foreach ([self::SIGNAL_TERMINATE, self::SIGNAL_INTERRUPT] as $signal) {
            $this->previousSignalHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(self::SIGNAL_TERMINATE, function (): void {
            $this->stopRequested = true;
        });
        pcntl_signal(self::SIGNAL_INTERRUPT, function (): void {
            $this->stopRequested = true;
        });
    }

    private function restoreSignals(): void
    {
        foreach ($this->previousSignalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        $this->previousSignalHandlers = [];

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
            $this->previousAsyncSignals = null;
        }
    }

    private function shouldStop(int $startedAt, int $startedMemory, int $processed): bool
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

        $currentMemory = memory_get_usage(true);
        if ($this->options->memoryLimitBytes !== null && $currentMemory >= $this->options->memoryLimitBytes) {
            return true;
        }

        return $this->options->maxMemoryGrowthBytes !== null
            && $currentMemory - $startedMemory >= $this->options->maxMemoryGrowthBytes;
    }
}

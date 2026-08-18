<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class WorkerPool
{
    private bool $stopRequested = false;

    /** @var array<int,int> */
    private array $children = [];

    /**
     * @param callable(int):Worker $workerFactory Factory invoked in the child after fork.
     */
    public function __construct(
        private readonly \Closure $workerFactory,
        private readonly int $concurrency = 1,
        private readonly int $maximumRestarts = 5,
        private readonly float $restartBackoffSeconds = 0.25,
    ) {
        if ($concurrency < 1 || $concurrency > 256) {
            throw new \InvalidArgumentException('Worker concurrency must be between 1 and 256.');
        }
        if ($maximumRestarts < 0) {
            throw new \InvalidArgumentException('Worker maximum restarts must be non-negative.');
        }
        if (!is_finite($restartBackoffSeconds) || $restartBackoffSeconds < 0.0) {
            throw new \InvalidArgumentException('Worker restart backoff must be finite and non-negative.');
        }
    }

    /**
     * @param callable(int):Worker $workerFactory
     */
    public static function create(
        callable $workerFactory,
        int $concurrency = 1,
        int $maximumRestarts = 5,
        float $restartBackoffSeconds = 0.25,
    ): self {
        return new self(
            \Closure::fromCallable($workerFactory),
            $concurrency,
            $maximumRestarts,
            $restartBackoffSeconds,
        );
    }

    public function run(): void
    {
        $this->assertSupported();
        $this->registerSignals();

        $restarts = array_fill(0, $this->concurrency, 0);
        for ($slot = 0; $slot < $this->concurrency; $slot++) {
            $this->spawn($slot);
        }

        while ($this->children !== []) {
            $status = 0;
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                continue;
            }

            $slot = $this->children[$pid] ?? null;
            unset($this->children[$pid]);
            if ($slot === null || $this->stopRequested) {
                continue;
            }

            $cleanExit = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
            if ($cleanExit) {
                continue;
            }

            if ($restarts[$slot] >= $this->maximumRestarts) {
                $this->stopRequested = true;
                $this->signalChildren(SIGTERM);

                continue;
            }

            $restarts[$slot]++;
            if ($this->restartBackoffSeconds > 0.0) {
                usleep((int) round($this->restartBackoffSeconds * $restarts[$slot] * 1_000_000));
            }
            $this->spawn($slot);
        }
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->signalChildren(SIGTERM);
    }

    private function assertSupported(): void
    {
        foreach (['pcntl_fork', 'pcntl_wait', 'pcntl_signal', 'posix_kill'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('WorkerPool requires ext-pcntl and ext-posix on a Unix-like runtime.');
            }
        }
    }

    private function registerSignals(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn(): null => $this->stopFromSignal());
        pcntl_signal(SIGINT, fn(): null => $this->stopFromSignal());
    }

    private function signalChildren(int $signal): void
    {
        foreach (array_keys($this->children) as $pid) {
            @posix_kill($pid, $signal);
        }
    }

    private function spawn(int $slot): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork Omnibus worker process.');
        }
        if ($pid > 0) {
            $this->children[$pid] = $slot;

            return;
        }

        try {
            $worker = ($this->workerFactory)($slot);
            $worker->run();
            exit(0);
        } catch (\Throwable) {
            exit(1);
        }
    }

    private function stopFromSignal(): null
    {
        $this->requestStop();

        return null;
    }
}

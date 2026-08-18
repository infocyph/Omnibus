<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class WorkerPool
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_TERMINATE = 15;

    /** @var array<int,int> */
    private array $children = [];

    private bool $stopRequested = false;

    /** @var \Closure(int):Worker */
    private readonly \Closure $workerFactory;

    /**
     * The factory is invoked in each child after fork. Create database,
     * Redis, broker and other process-bound resources inside that factory.
     *
     * @param callable(int):Worker $workerFactory
     */
    public function __construct(
        callable $workerFactory,
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

        $this->workerFactory = \Closure::fromCallable($workerFactory);
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->signalChildren(self::SIGNAL_TERMINATE);
    }

    public function run(): void
    {
        $this->assertSupported();
        $this->registerSignals();

        try {
            $this->supervise();
        } catch (\Throwable $failure) {
            $this->stopRequested = true;
            $this->signalChildren(self::SIGNAL_TERMINATE);
            $this->reapChildren();

            throw $failure;
        }
    }

    private function assertSupported(): void
    {
        foreach (['pcntl_fork', 'pcntl_wait', 'pcntl_signal', 'posix_kill'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'WorkerPool requires ext-pcntl and ext-posix on a Unix-like runtime.',
                );
            }
        }
    }

    private function reapChildren(): void
    {
        while ($this->children !== []) {
            $status = 0;
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                $this->children = [];

                return;
            }

            unset($this->children[$pid]);
        }
    }

    private function registerSignals(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(self::SIGNAL_TERMINATE, fn(): null => $this->stopFromSignal());
        pcntl_signal(self::SIGNAL_INTERRUPT, fn(): null => $this->stopFromSignal());
    }

    private function signalChildren(int $signal): void
    {
        if (!function_exists('posix_kill')) {
            return;
        }

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

    private function supervise(): void
    {
        $fatal = null;
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

            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $restarts[$slot] = 0;
                $this->spawn($slot);

                continue;
            }

            if ($restarts[$slot] >= $this->maximumRestarts) {
                $fatal = sprintf('Worker slot %d exhausted its restart budget.', $slot);
                $this->stopRequested = true;
                $this->signalChildren(self::SIGNAL_TERMINATE);

                continue;
            }

            $restarts[$slot]++;
            if ($this->restartBackoffSeconds > 0.0) {
                usleep((int) round($this->restartBackoffSeconds * $restarts[$slot] * 1_000_000));
            }
            $this->spawn($slot);
        }

        if ($fatal !== null) {
            throw new \RuntimeException($fatal);
        }
    }
}

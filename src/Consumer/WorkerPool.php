<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class WorkerPool
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_KILL = 9;

    private const int SIGNAL_TERMINATE = 15;

    /** @var array<int,int> */
    private array $children = [];

    private bool $killEscalated = false;

    /** @var array<int,callable|int> */
    private array $previousSignalHandlers = [];

    private ?bool $previousAsyncSignals = null;

    private ?float $shutdownDeadline = null;

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
        private readonly float $shutdownGraceSeconds = 30.0,
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
        if (!is_finite($shutdownGraceSeconds) || $shutdownGraceSeconds <= 0.0) {
            throw new \InvalidArgumentException('Worker shutdown grace must be positive and finite.');
        }

        $this->workerFactory = \Closure::fromCallable($workerFactory);
    }

    public function requestStop(): void
    {
        if (!$this->stopRequested) {
            $this->stopRequested = true;
            $this->shutdownDeadline = self::monotonicSeconds() + $this->shutdownGraceSeconds;
        }

        $this->signalChildren(self::SIGNAL_TERMINATE);
    }

    public function run(): void
    {
        $this->assertSupported();
        $this->registerSignals();

        try {
            if ($this->stopRequested) {
                return;
            }

            try {
                $this->supervise();
            } catch (\Throwable $failure) {
                $this->requestStop();
                $this->drainChildren();

                throw $failure;
            }
        } finally {
            $this->restoreSignals();
        }
    }

    private static function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function assertSupported(): void
    {
        foreach ([
            'pcntl_async_signals',
            'pcntl_fork',
            'pcntl_get_last_error',
            'pcntl_signal',
            'pcntl_signal_get_handler',
            'pcntl_wait',
            'pcntl_waitpid',
            'posix_kill',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'WorkerPool requires ext-pcntl and ext-posix on a Unix-like runtime.',
                );
            }
        }
    }

    private function consumeChildExit(int $pid, int $status, array &$restarts): ?string
    {
        $slot = $this->children[$pid] ?? null;
        unset($this->children[$pid]);
        if ($slot === null || $this->stopRequested) {
            return null;
        }

        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            $restarts[$slot] = 0;
            $this->spawn($slot);

            return null;
        }

        return $this->restartCrashedWorker($slot, $restarts);
    }

    private function drainChildren(): void
    {
        while ($this->children !== []) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                unset($this->children[$pid]);

                continue;
            }
            if ($pid === -1) {
                if (defined('PCNTL_EINTR') && pcntl_get_last_error() === PCNTL_EINTR) {
                    continue;
                }

                $this->children = [];

                return;
            }

            $this->escalateShutdownIfNeeded();
            if ($this->children !== []) {
                usleep(10_000);
            }
        }
    }

    private function escalateShutdownIfNeeded(): void
    {
        if (
            !$this->stopRequested
            || $this->killEscalated
            || $this->shutdownDeadline === null
            || self::monotonicSeconds() < $this->shutdownDeadline
        ) {
            return;
        }

        $this->killEscalated = true;
        $this->signalChildren(self::SIGNAL_KILL);
    }

    private function registerSignals(): void
    {
        foreach ([self::SIGNAL_TERMINATE, self::SIGNAL_INTERRUPT] as $signal) {
            $this->previousSignalHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(self::SIGNAL_TERMINATE, fn(): null => $this->stopFromSignal(), false);
        pcntl_signal(self::SIGNAL_INTERRUPT, fn(): null => $this->stopFromSignal(), false);
    }

    private function resetSignalsForChild(): void
    {
        pcntl_signal(self::SIGNAL_TERMINATE, SIG_DFL);
        pcntl_signal(self::SIGNAL_INTERRUPT, SIG_DFL);
    }

    private function restartCrashedWorker(int $slot, array &$restarts): ?string
    {
        if ($restarts[$slot] >= $this->maximumRestarts) {
            return sprintf('Worker slot %d exhausted its restart budget.', $slot);
        }

        $restarts[$slot]++;
        if ($this->restartBackoffSeconds > 0.0) {
            usleep((int) round($this->restartBackoffSeconds * $restarts[$slot] * 1_000_000));
        }
        if (!$this->stopRequested) {
            $this->spawn($slot);
        }

        return null;
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

    private function signalChildren(int $signal): void
    {
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, $signal);
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
            if ($this->stopRequested) {
                posix_kill($pid, self::SIGNAL_TERMINATE);
            }

            return;
        }

        $this->resetSignalsForChild();

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
        for ($slot = 0; $slot < $this->concurrency && !$this->stopRequested; $slot++) {
            $this->spawn($slot);
        }

        while ($this->children !== []) {
            if ($this->stopRequested) {
                $this->drainChildren();

                break;
            }

            $status = 0;
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                continue;
            }

            $fatal = $this->consumeChildExit($pid, $status, $restarts);
            if ($fatal !== null) {
                $this->requestStop();
            }
        }

        if ($fatal !== null) {
            throw new \RuntimeException($fatal);
        }
    }
}

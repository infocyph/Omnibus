<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class NativeWorkerPoolBackend implements WorkerPoolBackend
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_KILL = 9;

    private const int SIGNAL_TERMINATE = 15;

    private const int SUPERVISION_SLEEP_MICROSECONDS = 10_000;

    /** @var array<int,int> */
    private array $children = [];

    private bool $killEscalated = false;

    private ?bool $previousAsyncSignals = null;

    /** @var array<int,callable|int> */
    private array $previousSignalHandlers = [];

    private ?float $shutdownDeadline = null;

    private ?float $shutdownGraceSeconds = null;

    private bool $stopRequested = false;

    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->beginShutdown();
    }

    /** @param \Closure(int):Worker $workerFactory */
    public function run(
        \Closure $workerFactory,
        int $concurrency,
        int $maximumRestarts,
        float $restartBackoffSeconds,
        float $shutdownGraceSeconds,
        ?WorkerLifecycle $lifecycle,
        float $lifecycleIntervalSeconds,
    ): void {
        if ($this->stopRequested) {
            return;
        }

        $this->assertSupported();
        $this->shutdownGraceSeconds = $shutdownGraceSeconds;
        $this->registerSignals();

        try {
            try {
                $this->supervise(
                    $workerFactory,
                    $concurrency,
                    $maximumRestarts,
                    $restartBackoffSeconds,
                    $lifecycle,
                    $lifecycleIntervalSeconds,
                );
            } catch (\Throwable $failure) {
                $this->requestStop();
                $this->drainChildren();

                throw $failure;
            }
        } finally {
            $this->restoreSignals();
            $this->shutdownGraceSeconds = null;
        }
    }

    private static function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private static function waitStatus(mixed $status): int
    {
        if (!is_int($status)) {
            throw new \UnexpectedValueException('pcntl_waitpid() returned an invalid child status.');
        }

        return $status;
    }

    private function assertSupported(): void
    {
        foreach ([
            'pcntl_async_signals',
            'pcntl_fork',
            'pcntl_get_last_error',
            'pcntl_signal',
            'pcntl_signal_get_handler',
            'pcntl_sigprocmask',
            'pcntl_waitpid',
            'pcntl_wifexited',
            'pcntl_wexitstatus',
            'pcntl_wifsignaled',
            'pcntl_wtermsig',
            'posix_kill',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'The native WorkerPool backend requires ext-pcntl and ext-posix on a Unix-like runtime.',
                );
            }
        }
    }

    private function beginShutdown(): void
    {
        if ($this->children === [] || $this->shutdownGraceSeconds === null) {
            return;
        }
        $this->shutdownDeadline ??= self::monotonicSeconds() + $this->shutdownGraceSeconds;

        $this->signalChildren(self::SIGNAL_TERMINATE);
    }

    /** @param array<int,int> $restarts */
    private function consumeChildExit(
        int $pid,
        int $status,
        array &$restarts,
        \Closure $workerFactory,
        int $maximumRestarts,
        float $restartBackoffSeconds,
    ): ?string {
        $slot = $this->children[$pid] ?? null;
        unset($this->children[$pid]);
        if ($slot === null || $this->stopRequested) {
            return null;
        }

        $cleanExit = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
        $cleanSignal = pcntl_wifsignaled($status)
            && pcntl_wtermsig($status) === self::SIGNAL_TERMINATE;
        if ($cleanExit || $cleanSignal) {
            $restarts[$slot] = 0;
            $this->spawn($slot, $workerFactory);

            return null;
        }

        return $this->restartCrashedWorker(
            $slot,
            $restarts,
            $workerFactory,
            $maximumRestarts,
            $restartBackoffSeconds,
        );
    }

    private function drainChildren(): void
    {
        $this->beginShutdown();

        while ($this->children !== []) {
            if ($this->reapStoppedChild()) {
                continue;
            }

            $this->escalateShutdownIfNeeded();
            if ($this->children !== []) {
                usleep(self::SUPERVISION_SLEEP_MICROSECONDS);
            }
        }
    }

    private function escalateShutdownIfNeeded(): void
    {
        if (
            $this->killEscalated
            || $this->shutdownDeadline === null
            || self::monotonicSeconds() < $this->shutdownDeadline
        ) {
            return;
        }

        $this->killEscalated = true;
        $this->signalChildren(self::SIGNAL_KILL);
    }

    private function isWaitError(int $error, string $constant): bool
    {
        return defined($constant) && $error === constant($constant);
    }

    /** @return array{int,int}|null */
    private function pollChild(): ?array
    {
        $status = 0;
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid > 0) {
            return [$pid, self::waitStatus($status)];
        }
        if ($pid === 0) {
            return null;
        }

        $error = pcntl_get_last_error();
        if ($this->isWaitError($error, 'PCNTL_EINTR')) {
            return null;
        }
        if ($this->isWaitError($error, 'PCNTL_ECHILD')) {
            $this->children = [];

            return null;
        }

        throw new \RuntimeException(sprintf('Unable to wait for Omnibus worker process (pcntl error %d).', $error));
    }

    private function reapStoppedChild(): bool
    {
        $child = $this->pollChild();
        if ($child === null) {
            return false;
        }

        unset($this->children[$child[0]]);

        return true;
    }

    private function registerSignals(): void
    {
        foreach ([self::SIGNAL_TERMINATE, self::SIGNAL_INTERRUPT] as $signal) {
            $this->previousSignalHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(self::SIGNAL_TERMINATE, $this->stopFromSignal(...), false);
        pcntl_signal(self::SIGNAL_INTERRUPT, $this->stopFromSignal(...), false);
    }

    private function resetSignalsForChild(): void
    {
        pcntl_signal(self::SIGNAL_TERMINATE, SIG_DFL);
        pcntl_signal(self::SIGNAL_INTERRUPT, SIG_DFL);
        pcntl_async_signals(false);
        if (!pcntl_sigprocmask(SIG_UNBLOCK, [self::SIGNAL_TERMINATE, self::SIGNAL_INTERRUPT])) {
            throw new \RuntimeException('Unable to unblock Omnibus worker child signals.');
        }
    }

    /** @param array<int,int> $restarts */
    private function restartCrashedWorker(
        int $slot,
        array &$restarts,
        \Closure $workerFactory,
        int $maximumRestarts,
        float $restartBackoffSeconds,
    ): ?string {
        if ($restarts[$slot] >= $maximumRestarts) {
            return sprintf('Worker slot %d exhausted its restart budget.', $slot);
        }

        $restarts[$slot]++;
        if ($restartBackoffSeconds > 0.0) {
            usleep((int) round($restartBackoffSeconds * $restarts[$slot] * 1_000_000));
        }
        if (!$this->stopRequested) {
            $this->spawn($slot, $workerFactory);
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

    private function serviceLifecycle(
        ?WorkerLifecycle $lifecycle,
        float $intervalSeconds,
        float &$nextAt,
    ): void {
        if ($lifecycle === null) {
            return;
        }

        $now = self::monotonicSeconds();
        if ($now < $nextAt) {
            return;
        }

        $nextAt = $now + $intervalSeconds;
        $lifecycle->heartbeat();
        if ($lifecycle->stopRequested()) {
            $this->requestStop();
        }
    }

    private function signalChildren(int $signal): void
    {
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, $signal);
        }
    }

    /** @param \Closure(int):Worker $workerFactory */
    private function spawn(int $slot, \Closure $workerFactory): void
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

        try {
            $this->resetSignalsForChild();
            $worker = $workerFactory($slot);
            $worker->run();
            $this->terminateChild(self::SIGNAL_TERMINATE);
        } catch (\Throwable) {
            $this->terminateChild(self::SIGNAL_KILL);
        }
    }

    private function stopFromSignal(): void
    {
        $this->stopRequested = true;
    }

    /** @param \Closure(int):Worker $workerFactory */
    private function supervise(
        \Closure $workerFactory,
        int $concurrency,
        int $maximumRestarts,
        float $restartBackoffSeconds,
        ?WorkerLifecycle $lifecycle,
        float $lifecycleIntervalSeconds,
    ): void {
        $fatal = null;
        /** @var array<int,int> $restarts */
        $restarts = array_fill(0, $concurrency, 0);
        $nextLifecycleAt = self::monotonicSeconds();
        $this->serviceLifecycle($lifecycle, $lifecycleIntervalSeconds, $nextLifecycleAt);
        if ($this->stopRequested) {
            return;
        }

        for ($slot = 0; $slot < $concurrency && !$this->stopRequested; $slot++) {
            $this->spawn($slot, $workerFactory);
        }

        while ($this->children !== []) {
            $this->serviceLifecycle($lifecycle, $lifecycleIntervalSeconds, $nextLifecycleAt);
            if ($this->stopRequested) {
                $this->drainChildren();

                break;
            }

            $child = $this->pollChild();
            if ($child === null) {
                usleep(self::SUPERVISION_SLEEP_MICROSECONDS);

                continue;
            }

            $fatal = $this->consumeChildExit(
                $child[0],
                $child[1],
                $restarts,
                $workerFactory,
                $maximumRestarts,
                $restartBackoffSeconds,
            );
            if ($fatal !== null) {
                $this->requestStop();
            }
        }

        if ($fatal !== null) {
            throw new \RuntimeException($fatal);
        }
    }

    private function terminateChild(int $signal): never
    {
        $pid = getmypid();
        if (!is_int($pid) || !posix_kill($pid, $signal)) {
            throw new \RuntimeException('Unable to terminate Omnibus worker child process.');
        }

        while (true) {
            usleep(self::SUPERVISION_SLEEP_MICROSECONDS);
        }
    }
}

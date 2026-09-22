<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

final class RunwireWorkerPoolBackend implements WorkerPoolBackend
{
    private const float RESTART_WINDOW_SECONDS = 31_536_000.0;

    private bool $stopRequested = false;

    private ?Supervisor $supervisor = null;

    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->supervisor?->stop();
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

        $this->assertRunwireAvailable();
        if ($this->lifecycleRequestsStop($lifecycle)) {
            return;
        }

        $loop = new SelectLoop();
        $supervisor = $this->createSupervisor(
            $loop,
            $workerFactory,
            $concurrency,
            $maximumRestarts,
            $restartBackoffSeconds,
            $shutdownGraceSeconds,
        );
        $this->supervisor = $supervisor;
        $lifecycleFailure = null;
        $timer = $this->registerLifecycleTimer(
            $loop,
            $supervisor,
            $lifecycle,
            $lifecycleIntervalSeconds,
            $lifecycleFailure,
        );

        $supervisorFailure = $this->runSupervisor($loop, $supervisor, $timer);
        $this->throwFailure($lifecycleFailure, $supervisorFailure);
    }

    private function assertRunwireAvailable(): void
    {
        foreach ([Supervisor::class, WorkerContext::class, WorkerGroup::class] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    'The Runwire WorkerPool backend requires infocyph/runwire ^1.0.',
                );
            }
        }
    }

    /** @param \Closure(int):Worker $workerFactory */
    private function createSupervisor(
        SelectLoop $loop,
        \Closure $workerFactory,
        int $concurrency,
        int $maximumRestarts,
        float $restartBackoffSeconds,
        float $shutdownGraceSeconds,
    ): Supervisor {
        $supervisor = new Supervisor($loop);
        $supervisor->group(WorkerGroup::callbacks(
            name: 'omnibus',
            count: $concurrency,
            factory: static function (WorkerContext $context) use ($workerFactory): void {
                $worker = $workerFactory($context->slot);
                $context->ready();
                $worker->runManaged(new RunwireWorkerLifecycle($context));
                if (!$context->stopping()) {
                    $context->requestRecycle();
                }
            },
            restartPolicy: new RestartPolicy(
                maxRestarts: $maximumRestarts,
                windowSeconds: self::RESTART_WINDOW_SECONDS,
                initialBackoffSeconds: $restartBackoffSeconds,
                maxBackoffSeconds: max(
                    $restartBackoffSeconds,
                    $restartBackoffSeconds * max(1, $maximumRestarts),
                ),
            ),
            recyclePolicy: new WorkerRecyclePolicy(
                gracefulTimeoutSeconds: $shutdownGraceSeconds,
            ),
            automaticReady: false,
            readyTimeoutSeconds: max(10.0, $shutdownGraceSeconds),
            shutdownTimeoutSeconds: $shutdownGraceSeconds,
            reloadable: false,
        ));

        return $supervisor;
    }

    private function lifecycleRequestsStop(?WorkerLifecycle $lifecycle): bool
    {
        if ($lifecycle === null) {
            return false;
        }

        $lifecycle->heartbeat();
        if (!$lifecycle->stopRequested()) {
            return false;
        }

        $this->stopRequested = true;

        return true;
    }

    private function registerLifecycleTimer(
        SelectLoop $loop,
        Supervisor $supervisor,
        ?WorkerLifecycle $lifecycle,
        float $lifecycleIntervalSeconds,
        ?\Throwable &$lifecycleFailure,
    ): ?int {
        if ($lifecycle === null) {
            return null;
        }

        return $loop->repeat(
            $lifecycleIntervalSeconds,
            function () use ($lifecycle, $supervisor, &$lifecycleFailure): void {
                try {
                    $lifecycle->heartbeat();
                    if ($lifecycle->stopRequested()) {
                        $this->requestStop();
                    }
                } catch (\Throwable $failure) {
                    $lifecycleFailure ??= $failure;
                    $supervisor->stop();
                }
            },
        );
    }

    private function runSupervisor(SelectLoop $loop, Supervisor $supervisor, ?int $timer): ?SupervisorException
    {
        $failure = null;

        try {
            $supervisor->run();
        } catch (SupervisorException $error) {
            $failure = $error;
        } finally {
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            $this->supervisor = null;
        }

        return $failure;
    }

    private function throwFailure(?\Throwable $lifecycleFailure, ?SupervisorException $supervisorFailure): void
    {
        if ($lifecycleFailure !== null) {
            throw $lifecycleFailure;
        }
        if ($supervisorFailure === null) {
            return;
        }
        if (str_contains($supervisorFailure->getMessage(), 'Restart budget exhausted')) {
            throw new \RuntimeException(
                'WorkerPool exhausted its restart budget.',
                previous: $supervisorFailure,
            );
        }

        throw new \RuntimeException(
            'Runwire WorkerPool supervision failed: ' . $supervisorFailure->getMessage(),
            previous: $supervisorFailure,
        );
    }
}

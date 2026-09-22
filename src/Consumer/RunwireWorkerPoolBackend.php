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

        $this->assertSupported();
        if ($lifecycle !== null) {
            $lifecycle->heartbeat();
            if ($lifecycle->stopRequested()) {
                $this->stopRequested = true;

                return;
            }
        }

        $loop = new SelectLoop();
        $supervisor = new Supervisor($loop);
        $this->supervisor = $supervisor;
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

        $lifecycleFailure = null;
        $timer = null;
        if ($lifecycle !== null) {
            $timer = $loop->repeat(
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

        $supervisorFailure = null;
        try {
            $supervisor->run();
        } catch (SupervisorException $failure) {
            $supervisorFailure = $failure;
        } finally {
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            $this->supervisor = null;
        }

        if ($lifecycleFailure !== null) {
            throw $lifecycleFailure;
        }
        if ($supervisorFailure !== null) {
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

    private function assertSupported(): void
    {
        foreach ([
            Supervisor::class,
            WorkerContext::class,
            WorkerGroup::class,
        ] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    'The Runwire WorkerPool backend requires infocyph/runwire ^1.0.',
                );
            }
        }

        foreach (['pcntl_fork', 'posix_kill'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'The Runwire WorkerPool backend requires Runwire native process capabilities.',
                );
            }
        }
    }
}

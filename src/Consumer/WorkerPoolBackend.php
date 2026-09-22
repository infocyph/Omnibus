<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

interface WorkerPoolBackend
{
    public function requestStop(): void;

    /** @param \Closure(int):Worker $workerFactory */
    public function run(
        \Closure $workerFactory,
        int $concurrency,
        int $maximumRestarts,
        float $restartBackoffSeconds,
        float $shutdownGraceSeconds,
        ?WorkerLifecycle $lifecycle,
        float $lifecycleIntervalSeconds,
    ): void;
}

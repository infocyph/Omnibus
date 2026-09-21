<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

interface WorkerPoolBackend
{
    /** @param \Closure(int):Worker $workerFactory */
    public function run(
        \Closure $workerFactory,
        int $concurrency,
        int $maximumRestarts,
        float $restartBackoffSeconds,
        float $shutdownGraceSeconds,
    ): void;

    public function requestStop(): void;
}

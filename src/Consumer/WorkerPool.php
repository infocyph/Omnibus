<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final class WorkerPool
{
    /** @var \Closure(int):Worker */
    private readonly \Closure $workerFactory;

    private readonly WorkerPoolBackend $backend;

    /**
     * The factory is invoked in each child after process creation. Create
     * database, Redis, broker and other process-bound resources inside it.
     *
     * @param callable(int):Worker $workerFactory
     */
    public function __construct(
        callable $workerFactory,
        private readonly int $concurrency = 1,
        private readonly int $maximumRestarts = 5,
        private readonly float $restartBackoffSeconds = 0.25,
        private readonly float $shutdownGraceSeconds = 30.0,
        ?WorkerPoolBackend $backend = null,
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
        $this->backend = $backend ?? new NativeWorkerPoolBackend();
    }

    public function requestStop(): void
    {
        $this->backend->requestStop();
    }

    public function run(): void
    {
        $this->backend->run(
            $this->workerFactory,
            $this->concurrency,
            $this->maximumRestarts,
            $this->restartBackoffSeconds,
            $this->shutdownGraceSeconds,
        );
    }
}

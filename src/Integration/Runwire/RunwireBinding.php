<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Runwire;

use Fiber;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use LogicException;
use WeakMap;

final class RunwireBinding
{
    /** @var WeakMap<Connection, bool> */
    private WeakMap $connections;

    /**
     * @var WeakMap<
     *   Fiber<mixed, mixed, mixed, mixed>,
     *   array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null}
     * >
     */
    private WeakMap $fiberContexts;

    /** @var array<string, int> */
    private array $generations = [];

    /** @var array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null}|null */
    private ?array $rootContext = null;

    public function __construct()
    {
        $this->connections = new WeakMap();
        $this->fiberContexts = new WeakMap();
    }

    public function registerConnection(Connection $connection): void
    {
        $this->connections[$connection] = true;
    }

    public function request(): ?RequestContext
    {
        return $this->context()['request'] ?? null;
    }

    public function checkpoint(): void
    {
        $context = $this->context();
        if ($context !== null) {
            $this->assertContext($context['runtime'], $context['request'], $context['scope']);
        }
    }

    public function isCancellationRequested(): bool
    {
        $context = $this->context();

        return $context !== null
            && (
                $context['request']?->cancellation->isCancelled() === true
                || $context['scope']?->cancellation()->isCancelled() === true
            );
    }

    public function remainingSeconds(): ?float
    {
        $context = $this->context();
        if ($context === null) {
            return null;
        }

        $remaining = array_values(array_filter([
            $context['request']?->cancellation->deadline()->remainingSeconds(),
            $context['scope']?->cancellation()->deadline()->remainingSeconds(),
        ], static fn(?float $seconds): bool => $seconds !== null));

        return $remaining === [] ? null : min($remaining);
    }

    public function isHostCancellation(\Throwable $failure): bool
    {
        return $failure instanceof CancelledException;
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function cleanup(callable $callback, float $maxSeconds = 5.0): mixed
    {
        if (!is_finite($maxSeconds) || $maxSeconds <= 0.0) {
            throw new \InvalidArgumentException('Runwire cleanup budget must be positive and finite.');
        }

        $current = $this->context();
        if ($current === null) {
            return $callback();
        }

        $this->assertRuntime($current['runtime']);
        $cleanupRequest = RequestContext::create(
            $current['runtime'],
            new RequestExecutionPolicy(maxExecutionSeconds: $maxSeconds),
            requestId: ($current['request']?->requestId ?? 'omnibus') . ':cleanup',
        );
        $fiber = Fiber::getCurrent();
        $this->setContext($fiber, [
            'runtime' => $current['runtime'],
            'request' => $cleanupRequest,
            'scope' => null,
        ]);

        try {
            return $this->run($callback);
        } finally {
            $cleanupRequest->complete();
            $this->setContext($fiber, $current);
        }
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        $context = $this->context();
        if ($context === null) {
            return $callback();
        }

        $this->assertContext($context['runtime'], $context['request'], $context['scope']);
        $operation = static fn(): mixed => $callback();

        if (
            class_exists(CacheRunwireIntegration::class)
            && CacheRunwireIntegration::runtime() === $context['runtime']
        ) {
            $next = $operation;
            $operation = static fn(): mixed => CacheRunwireIntegration::share(
                $context['request'],
                $context['scope'],
                $next,
            );
        }

        foreach ($this->connections as $connection => $_registered) {
            unset($_registered);
            $next = $operation;
            $operation = static fn(): mixed => $connection->withRunwire(
                $context['runtime'],
                $next,
                $context['request'],
                $context['scope'],
            );
        }

        return $operation();
    }

    public function runtime(): ?RuntimeContext
    {
        return $this->context()['runtime'] ?? null;
    }

    public function scope(): ?CoroutineScope
    {
        return $this->context()['scope'] ?? null;
    }

    public function sleep(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            throw new \InvalidArgumentException('Runwire-aware sleep must be finite and non-negative.');
        }
        if ($seconds === 0.0) {
            return;
        }

        $context = $this->context();
        if ($context === null) {
            usleep((int) min(PHP_INT_MAX, ceil($seconds * 1_000_000)));

            return;
        }

        $this->assertContext($context['runtime'], $context['request'], $context['scope']);
        if (
            $context['scope'] !== null
            && $context['runtime']->supports(RuntimeCapability::RUNWIRE_COROUTINES)
        ) {
            $context['scope']->sleep($seconds);
        } else {
            usleep((int) min(PHP_INT_MAX, ceil($seconds * 1_000_000)));
        }
        $this->assertContext($context['runtime'], $context['request'], $context['scope']);
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function withRunwire(
        RuntimeContext $runtime,
        callable $callback,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): mixed {
        $current = $this->context();
        if ($current !== null && $current['runtime'] !== $runtime) {
            throw new LogicException('Omnibus cannot switch Runwire runtime inside a nested binding.');
        }
        if ($current !== null && $request !== null && $current['request'] !== null && $current['request'] !== $request) {
            throw new LogicException('Omnibus cannot switch Runwire request inside a nested binding.');
        }
        if ($current !== null && $scope !== null && $current['scope'] !== null && $current['scope'] !== $scope) {
            throw new LogicException('Omnibus cannot switch Runwire coroutine scope inside a nested binding.');
        }

        $effectiveRequest = $request ?? $current['request'] ?? null;
        $effectiveScope = $scope ?? $current['scope'] ?? null;
        $this->assertContext($runtime, $effectiveRequest, $effectiveScope);
        $this->rememberGeneration($runtime);

        $next = [
            'runtime' => $runtime,
            'request' => $effectiveRequest,
            'scope' => $effectiveScope,
        ];
        $fiber = Fiber::getCurrent();
        $hadPrevious = $current !== null;
        $this->setContext($fiber, $next);

        try {
            return $this->run($callback);
        } finally {
            $this->setContext($fiber, $hadPrevious ? $current : null);
        }
    }

    private function assertContext(
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
    ): void {
        $this->assertRuntime($runtime);
        if ($request !== null && $request->runtime() !== $runtime) {
            throw new LogicException('Runwire request context belongs to a different runtime.');
        }
        if ($request?->completed() === true) {
            throw new LogicException('Completed Runwire request context cannot enter Omnibus.');
        }

        $request?->cancellation->throwIfCancelled();
        $scope?->cancellation()->throwIfCancelled();
    }

    /** @return array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null}|null */
    private function context(): ?array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->rootContext;
        }

        return $this->fiberContexts[$fiber] ?? null;
    }

    private function assertRuntime(RuntimeContext $runtime): void
    {
        $pid = getmypid();
        $currentPid = is_int($pid) ? $pid : 0;
        if ($runtime->pid !== $currentPid) {
            throw new LogicException('Runwire runtime PID does not match the current Omnibus process.');
        }
    }

    private function rememberGeneration(RuntimeContext $runtime): void
    {
        if ($runtime->generation === null) {
            return;
        }

        $key = implode(':', [
            $runtime->driver->value,
            $runtime->mode,
            (string) ($runtime->workerSlot ?? -1),
        ]);
        $latest = $this->generations[$key] ?? null;
        if ($latest !== null && $runtime->generation < $latest) {
            throw new LogicException('Stale Runwire runtime generation cannot enter Omnibus.');
        }

        $this->generations[$key] = max($latest ?? $runtime->generation, $runtime->generation);
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed>|null $fiber
     * @param array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null}|null $context
     */
    private function setContext(?Fiber $fiber, ?array $context): void
    {
        if ($fiber === null) {
            $this->rootContext = $context;

            return;
        }
        if ($context === null) {
            unset($this->fiberContexts[$fiber]);

            return;
        }

        $this->fiberContexts[$fiber] = $context;
    }
}

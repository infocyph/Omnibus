<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Runwire;

use Fiber;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use LogicException;
use WeakMap;

final class RunwireBinding
{
    /** @var WeakMap<Connection, bool>|null */
    private ?WeakMap $connections = null;

    /**
     * @var WeakMap<
     *   Fiber<mixed, mixed, mixed, mixed>,
     *   array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null,generationKey:string|null}
     * >|null
     */
    private ?WeakMap $fiberContexts = null;

    /** @var array<string, int> */
    private array $generations = [];

    /** @var array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null,generationKey:string|null}|null */
    private ?array $rootContext = null;

    public function checkpoint(): void
    {
        $context = $this->context();
        if ($context !== null) {
            $this->assertContext($context['runtime'], $context['request'], $context['scope'], $context['generationKey']);
        }
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

        $this->assertContext($current['runtime'], null, null, $current['generationKey']);
        $cleanupRequest = RequestContext::create(
            $current['runtime'],
            new RequestExecutionPolicy(maxExecutionSeconds: $maxSeconds),
        );
        $fiber = Fiber::getCurrent();
        $this->setContext($fiber, [
            'runtime' => $current['runtime'],
            'request' => $cleanupRequest,
            'scope' => null,
            'generationKey' => $current['generationKey'],
        ]);

        try {
            return $this->run($callback);
        } finally {
            $cleanupRequest->complete();
            $this->setContext($fiber, $current);
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

    public function isHostCancellation(\Throwable $failure): bool
    {
        return $failure instanceof CancelledException;
    }

    public function registerConnection(Connection $connection): void
    {
        $this->connections ??= new WeakMap();
        $this->connections[$connection] = true;
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

    public function request(): ?RequestContext
    {
        return $this->context()['request'] ?? null;
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

        $this->assertContext($context['runtime'], $context['request'], $context['scope'], $context['generationKey']);
        // A host binding loads this integration; an unbound optional package
        // should not be autoloaded merely to inspect its empty runtime state.
        $cacheBound = \class_exists(CacheRunwireIntegration::class, false)
            && CacheRunwireIntegration::runtime() === $context['runtime'];
        if (!$cacheBound && $this->connections === null) {
            return $callback();
        }

        $operation = $callback;
        if ($cacheBound) {
            $next = $operation;
            $operation = static fn(): mixed => CacheRunwireIntegration::share(
                $context['request'],
                $context['scope'],
                $next,
            );
        }

        foreach ($this->connections ?? [] as $connection => $_registered) {
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

        $this->assertContext($context['runtime'], $context['request'], $context['scope'], $context['generationKey']);
        if (
            $context['scope'] !== null
            && $context['runtime']->supports(RuntimeCapability::RUNWIRE_COROUTINES)
        ) {
            $context['scope']->sleep($seconds);
        } else {
            usleep((int) min(PHP_INT_MAX, ceil($seconds * 1_000_000)));
        }
        $this->assertContext($context['runtime'], $context['request'], $context['scope'], $context['generationKey']);
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
        $fiber = Fiber::getCurrent();
        $current = $fiber === null ? $this->rootContext : ($this->fiberContexts[$fiber] ?? null);
        if ($current !== null) {
            $this->assertNestedContext($current, $runtime, $request, $scope);
        }

        $effectiveRequest = $request ?? $current['request'] ?? null;
        $effectiveScope = $scope ?? $current['scope'] ?? null;
        // Runtime identity is immutable; reuse its key within this binding,
        // but read the live generation and cancellation state at every boundary.
        $generationKey = $current['generationKey'] ?? ($runtime->generation === null
            ? null
            : $runtime->driver->value . ':' . $runtime->mode . ':' . ($runtime->workerSlot ?? -1));
        $this->assertContext($runtime, $effectiveRequest, $effectiveScope, $generationKey);
        if ($runtime->generation !== null && $generationKey !== null) {
            $this->generations[$generationKey] = $runtime->generation;
        }

        $next = [
            'runtime' => $runtime,
            'request' => $effectiveRequest,
            'scope' => $effectiveScope,
            'generationKey' => $generationKey,
        ];
        if ($fiber === null) {
            $this->rootContext = $next;
        } else {
            $this->setContext($fiber, $next);
        }

        try {
            // The entry context was already validated. Without borrowed adapters,
            // avoid repeating that validation before invoking the caller.
            // Nested bus/consumer operations still validate via run().
            if ($this->connections === null
                && (!\class_exists(CacheRunwireIntegration::class, false)
                    || CacheRunwireIntegration::runtime() !== $runtime)) {
                return $callback();
            }

            return $this->run($callback);
        } finally {
            if ($fiber === null) {
                $this->rootContext = $current;
            } else {
                $this->setContext($fiber, $current);
            }
        }
    }

    private function assertContext(
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        ?string $generationKey,
    ): void {
        if ($runtime->pid !== \getmypid()) {
            throw new LogicException('Runwire runtime PID does not match the current Omnibus process.');
        }
        if ($generationKey !== null) {
            $latest = $this->generations[$generationKey] ?? null;
            if ($latest !== null && $runtime->generation < $latest) {
                throw new LogicException('Stale Runwire runtime generation cannot enter Omnibus.');
            }
        }
        if ($request !== null && $request->runtime() !== $runtime) {
            throw new LogicException('Runwire request context belongs to a different runtime.');
        }
        if ($request?->completed() === true) {
            throw new LogicException('Completed Runwire request context cannot enter Omnibus.');
        }

        $request?->cancellation->throwIfCancelled();
        if ($scope !== null) {
            // Runwire 2.1 exposes its scope lifecycle through guarded operations,
            // not a public isClosed() query. Reject stale scopes before business work.
            $scope->barrier(1);
            $scope->cancellation()->throwIfCancelled();
        }
    }

    /** @param array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null,generationKey:string|null} $current */
    private function assertNestedContext(
        array $current,
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
    ): void {
        if ($current['runtime'] !== $runtime) {
            throw new LogicException('Omnibus cannot switch Runwire runtime inside a nested binding.');
        }
        if ($request !== null && $current['request'] !== null && $current['request'] !== $request) {
            throw new LogicException('Omnibus cannot switch Runwire request inside a nested binding.');
        }
        if ($scope !== null && $current['scope'] !== null && $current['scope'] !== $scope) {
            throw new LogicException('Omnibus cannot switch Runwire coroutine scope inside a nested binding.');
        }
    }

    /** @return array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null,generationKey:string|null}|null */
    private function context(): ?array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->rootContext;
        }

        return $this->fiberContexts[$fiber] ?? null;
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed>|null $fiber
     * @param array{runtime:RuntimeContext,request:RequestContext|null,scope:CoroutineScope|null,generationKey:string|null}|null $context
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

        $this->fiberContexts ??= new WeakMap();
        $this->fiberContexts[$fiber] = $context;
    }
}

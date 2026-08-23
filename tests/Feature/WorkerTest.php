<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\RecordingHandlerMiddleware;
use Infocyph\Omnibus\Tests\Fixtures\RecordingWorkerLifecycle;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('worker respects message bounds without prefetch overshoot', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = [];
    $middlewareEvents = [];

    foreach (['one', 'two', 'three'] as $value) {
        $transport->send(new Envelope(new TestCommand($value)), 'work');
    }

    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerInvoker(
                new HandlerMap([
                    TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                        $handled[] = $message->value;
                    },
                ]),
                [new RecordingHandlerMiddleware(
                    'worker',
                    static function (string $event) use (&$middlewareEvents): void {
                        $middlewareEvents[] = $event;
                    },
                )],
            ),
            new ExponentialRetryStrategy(initialDelaySeconds: 0),
            new InMemoryFailureStore(),
            $clock,
        ),
        new WorkerOptions(
            queue: 'work',
            prefetch: 10,
            maxMessages: 2,
            handleSignals: false,
        ),
    );

    $worker->run();

    expect($handled)->toBe(['one', 'two'])
        ->and($middlewareEvents)->toBe([
            'before:worker',
            'after:worker',
            'before:worker',
            'after:worker',
        ])
        ->and($transport->size('work'))->toBe(1);
});

test('worker lifecycle heartbeats across iterations and preserves one integration object', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = 0;
    $instances = [];
    foreach (['one', 'two'] as $value) {
        $transport->send(new Envelope(new TestCommand($value)), 'work');
    }
    $lifecycle = new RecordingWorkerLifecycle(
        static function (int $heartbeat, RecordingWorkerLifecycle $instance) use (&$instances): void {
            $instances[$heartbeat] = spl_object_id($instance);
        },
    );
    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerInvoker(new HandlerMap([
                TestCommand::class => static function () use (&$handled): void {
                    $handled++;
                },
            ])),
            new ExponentialRetryStrategy(),
            new InMemoryFailureStore(),
            $clock,
        ),
        new WorkerOptions(queue: 'work', prefetch: 1, maxMessages: 2, handleSignals: false),
        $lifecycle,
    );

    $worker->run();

    expect($handled)->toBe(2)
        ->and($lifecycle->heartbeats)->toBe(3)
        ->and($lifecycle->stopChecks)->toBe(5)
        ->and(array_values(array_unique($instances)))->toBe([spl_object_id($lifecycle)]);
});

test('external lifecycle stops before the first receive without requiring signals', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('pending')), 'work');
    $lifecycle = new RecordingWorkerLifecycle(
        onStopRequested: static fn(): bool => true,
    );
    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerInvoker(new HandlerMap([
                TestCommand::class => static function (): void {},
            ])),
            new ExponentialRetryStrategy(),
            new InMemoryFailureStore(),
            $clock,
        ),
        new WorkerOptions(queue: 'work', handleSignals: false),
        $lifecycle,
    );

    $worker->run();

    expect($transport->size('work'))->toBe(1)
        ->and($lifecycle->heartbeats)->toBe(1)
        ->and($lifecycle->stopChecks)->toBe(1);
});

test('external lifecycle stop after a batch prevents the next receive', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = 0;
    foreach (['one', 'two'] as $value) {
        $transport->send(new Envelope(new TestCommand($value)), 'work');
    }
    $lifecycle = new RecordingWorkerLifecycle(
        onStopRequested: static fn(int $checks): bool => $checks >= 2,
    );
    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerInvoker(new HandlerMap([
                TestCommand::class => static function () use (&$handled): void {
                    $handled++;
                },
            ])),
            new ExponentialRetryStrategy(),
            new InMemoryFailureStore(),
            $clock,
        ),
        new WorkerOptions(queue: 'work', prefetch: 1, handleSignals: false),
        $lifecycle,
    );

    $worker->run();

    expect($handled)->toBe(1)
        ->and($transport->size('work'))->toBe(1)
        ->and($lifecycle->heartbeats)->toBe(2)
        ->and($lifecycle->stopChecks)->toBe(2);
});

test('worker lifecycle exceptions escape unchanged', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $consumer = new Consumer(
        new InMemoryTransport($clock),
        new HandlerInvoker(new HandlerMap([])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
    );
    $heartbeatFailure = new RuntimeException('heartbeat unavailable');
    $stopFailure = new DomainException('stop backend unavailable');
    $heartbeat = new RecordingWorkerLifecycle(
        onHeartbeat: static fn() => throw $heartbeatFailure,
    );
    $stop = new RecordingWorkerLifecycle(
        onStopRequested: static fn() => throw $stopFailure,
    );

    expect(fn() => (new Worker($consumer, lifecycle: $heartbeat))->run())
        ->toThrow(RuntimeException::class, 'heartbeat unavailable')
        ->and(fn() => (new Worker($consumer, lifecycle: $stop))->run())
        ->toThrow(DomainException::class, 'stop backend unavailable');
});

test('request stop and memory limits still prevent receiving', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('pending')), 'work');
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([TestCommand::class => static function (): void {}])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
    );
    $requested = new Worker($consumer, new WorkerOptions(queue: 'work', handleSignals: false));
    $requested->requestStop();
    $requested->run();
    $memoryLimited = new Worker(
        $consumer,
        new WorkerOptions(queue: 'work', memoryLimitBytes: 1, handleSignals: false),
    );
    $memoryLimited->run();

    expect($transport->size('work'))->toBe(1);
});

test('runtime limits and idle backoff remain cooperative lifecycle boundaries', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $consumer = new Consumer(
        new InMemoryTransport($clock),
        new HandlerInvoker(new HandlerMap([])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
    );
    $runtimeWorker = new Worker(
        $consumer,
        new WorkerOptions(
            idleSleepSeconds: 0.01,
            maxIdleSleepSeconds: 0.01,
            idleJitterRatio: 0,
            maxRuntimeSeconds: 0.005,
            handleSignals: false,
        ),
    );
    $runtimeStarted = microtime(true);
    $runtimeWorker->run();
    $runtimeElapsed = microtime(true) - $runtimeStarted;

    $lifecycle = new RecordingWorkerLifecycle(
        onStopRequested: static fn(int $checks): bool => $checks >= 5,
    );
    $idleWorker = new Worker(
        $consumer,
        new WorkerOptions(
            idleSleepSeconds: 0.002,
            maxIdleSleepSeconds: 0.004,
            idleJitterRatio: 0,
            handleSignals: false,
        ),
        $lifecycle,
    );
    $idleStarted = microtime(true);
    $idleWorker->run();
    $idleElapsed = microtime(true) - $idleStarted;

    expect($runtimeElapsed)->toBeGreaterThanOrEqual(0.005)
        ->and($runtimeElapsed)->toBeLessThan(0.2)
        ->and($idleElapsed)->toBeGreaterThanOrEqual(0.005)
        ->and($idleElapsed)->toBeLessThan(0.2)
        ->and($lifecycle->heartbeats)->toBe(5)
        ->and($lifecycle->stopChecks)->toBe(5);
});

test('worker restores parent signal handlers after execution', function (): void {
    if (!function_exists('pcntl_signal_get_handler')) {
        $this->markTestSkipped('Worker signal restoration requires ext-pcntl.');
    }

    $previous = pcntl_signal_get_handler(15);
    $handler = static function (): void {};
    pcntl_signal(15, $handler);

    try {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $transport = new InMemoryTransport($clock);
        $transport->send(new Envelope(new TestCommand('one')), 'work');
        $worker = new Worker(
            new Consumer(
                $transport,
                new HandlerInvoker(new HandlerMap([TestCommand::class => static function (): void {}])),
                new ExponentialRetryStrategy(),
                new InMemoryFailureStore(),
                $clock,
            ),
            new WorkerOptions(queue: 'work', maxMessages: 1),
        );

        $worker->run();

        expect(pcntl_signal_get_handler(15))->toBe($handler);
    } finally {
        pcntl_signal(15, $previous);
    }
});

test('worker options reject unsafe bounds', function (): void {
    expect(fn() => new WorkerOptions(prefetch: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerOptions(visibilitySeconds: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerOptions(idleJitterRatio: 1.1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerOptions(maxMessages: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerOptions(maxMemoryGrowthBytes: 0))->toThrow(InvalidArgumentException::class);
});

test('worker pool validates process limits before execution', function (): void {
    $factory = static function (int $slot): Worker {
        throw new LogicException((string) $slot);
    };

    expect(fn() => new WorkerPool($factory, concurrency: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerPool($factory, maximumRestarts: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerPool($factory, restartBackoffSeconds: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerPool($factory, shutdownGraceSeconds: 0))->toThrow(InvalidArgumentException::class);
});

test('worker pool stops after the bounded crash restart budget', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('WorkerPool requires ext-pcntl and ext-posix.');
    }

    $pool = new WorkerPool(
        static function (int $slot): Worker {
            throw new RuntimeException('crash-' . $slot);
        },
        concurrency: 1,
        maximumRestarts: 1,
        restartBackoffSeconds: 0,
    );

    expect(fn() => $pool->run())
        ->toThrow(RuntimeException::class, 'exhausted its restart budget');
});

test('worker pool replaces cleanly recycled workers without consuming the crash budget', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('WorkerPool requires ext-pcntl and ext-posix.');
    }

    $counter = tempnam(sys_get_temp_dir(), 'omnibus-worker-recycle-');
    if ($counter === false) {
        throw new RuntimeException('Unable to allocate a worker recycle counter.');
    }
    file_put_contents($counter, '0');
    $middlewareLog = $counter . '-middleware';
    file_put_contents($middlewareLog, '');

    try {
        $pool = new WorkerPool(
            static function () use ($counter, $middlewareLog): Worker {
                $cycle = (int) file_get_contents($counter) + 1;
                file_put_contents($counter, (string) $cycle, LOCK_EX);
                if ($cycle > 2) {
                    throw new RuntimeException('end-recycle-test');
                }

                $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                $transport = new InMemoryTransport($clock);
                $transport->send(new Envelope(new TestCommand((string) $cycle)), 'work');

                return new Worker(
                    new Consumer(
                        $transport,
                        new HandlerInvoker(
                            new HandlerMap([TestCommand::class => static function (): void {}]),
                            [new RecordingHandlerMiddleware(
                                'pool',
                                static function (string $event) use ($middlewareLog): void {
                                    if ($event === 'before:pool') {
                                        file_put_contents($middlewareLog, "handled\n", FILE_APPEND | LOCK_EX);
                                    }
                                },
                            )],
                        ),
                        new ExponentialRetryStrategy(),
                        new InMemoryFailureStore(),
                        $clock,
                    ),
                    new WorkerOptions(queue: 'work', maxMessages: 1, handleSignals: false),
                );
            },
            maximumRestarts: 0,
            restartBackoffSeconds: 0,
        );

        expect(fn() => $pool->run())
            ->toThrow(RuntimeException::class, 'exhausted its restart budget');
        expect(file_get_contents($counter))->toBe('3')
            ->and(file($middlewareLog, FILE_IGNORE_NEW_LINES))->toBe(['handled', 'handled']);
    } finally {
        unlink($counter);
        unlink($middlewareLog);
    }
});

test('worker pool force kills a child that ignores graceful shutdown', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('WorkerPool requires ext-pcntl and ext-posix.');
    }

    $pool = new WorkerPool(
        static function (int $slot): Worker {
            if ($slot === 0) {
                usleep(100_000);
                throw new RuntimeException('trigger-pool-stop');
            }

            pcntl_signal(15, SIG_IGN);
            $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

            return new Worker(
                new Consumer(
                    new InMemoryTransport($clock),
                    new HandlerInvoker(new HandlerMap([])),
                    new ExponentialRetryStrategy(),
                    new InMemoryFailureStore(),
                    $clock,
                ),
                new WorkerOptions(
                    idleSleepSeconds: 0.001,
                    maxIdleSleepSeconds: 0.001,
                    handleSignals: false,
                ),
            );
        },
        concurrency: 2,
        maximumRestarts: 0,
        restartBackoffSeconds: 0,
        shutdownGraceSeconds: 0.05,
    );

    $startedAt = hrtime(true);

    expect(fn() => $pool->run())
        ->toThrow(RuntimeException::class, 'exhausted its restart budget');

    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
    expect($elapsed)->toBeGreaterThanOrEqual(0.12)
        ->and($elapsed)->toBeLessThan(2.0);
});

test('worker pool restores parent signal handlers after execution', function (): void {
    if (
        !function_exists('pcntl_fork')
        || !function_exists('pcntl_signal_get_handler')
        || !function_exists('posix_kill')
    ) {
        $this->markTestSkipped('WorkerPool requires ext-pcntl and ext-posix.');
    }

    $previous = pcntl_signal_get_handler(15);
    $handler = static function (): void {};
    pcntl_signal(15, $handler);

    try {
        $pool = new WorkerPool(
            static function (): Worker {
                throw new RuntimeException('stop');
            },
            maximumRestarts: 0,
            restartBackoffSeconds: 0,
            shutdownGraceSeconds: 0.05,
        );

        expect(fn() => $pool->run())
            ->toThrow(RuntimeException::class, 'exhausted its restart budget');
        expect(pcntl_signal_get_handler(15))->toBe($handler);
    } finally {
        pcntl_signal(15, $previous);
    }
});

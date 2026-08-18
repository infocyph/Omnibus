<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('worker respects message bounds without prefetch overshoot', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = [];

    foreach (['one', 'two', 'three'] as $value) {
        $transport->send(new Envelope(new TestCommand($value)), 'work');
    }

    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerMap([
                TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                    $handled[] = $message->value;
                },
            ]),
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
        ->and($transport->size('work'))->toBe(1);
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

test('worker pool force kills a child that ignores graceful shutdown', function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('WorkerPool requires ext-pcntl and ext-posix.');
    }

    $pool = new WorkerPool(
        static function (int $slot): Worker {
            if ($slot === 0) {
                throw new RuntimeException('trigger-pool-stop');
            }

            pcntl_signal(15, SIG_IGN);
            $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

            return new Worker(
                new Consumer(
                    new InMemoryTransport($clock),
                    new HandlerMap([]),
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

    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(2.0);
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

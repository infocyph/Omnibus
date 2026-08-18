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
        ->and(fn() => new WorkerOptions(maxMessages: 0))->toThrow(InvalidArgumentException::class);
});

test('worker pool validates process limits before execution', function (): void {
    $factory = static fn(int $slot): Worker => throw new LogicException((string) $slot);

    expect(fn() => new WorkerPool($factory, concurrency: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerPool($factory, maximumRestarts: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkerPool($factory, restartBackoffSeconds: -1))->toThrow(InvalidArgumentException::class);
});

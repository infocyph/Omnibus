<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\RunwireWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\RecordingWorkerLifecycle;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

function omnibusRunwireIdleWorker(): Worker
{
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
        ),
    );
}

test('runwire worker pool stop before run creates no child', function (): void {
    $created = false;
    $pool = new WorkerPool(
        static function () use (&$created): Worker {
            $created = true;

            throw new RuntimeException('factory must not run');
        },
        backend: new RunwireWorkerPoolBackend(),
    );

    $pool->requestStop();
    $pool->requestStop();
    $pool->run();

    expect($created)->toBeFalse();
});

test('runwire worker pool services parent lifecycle and drains workers', function (): void {
    $marker = tempnam(sys_get_temp_dir(), 'omnibus-runwire-lifecycle-');
    if ($marker === false) {
        throw new RuntimeException('Unable to allocate Runwire lifecycle marker.');
    }
    unlink($marker);

    try {
        $lifecycle = new RecordingWorkerLifecycle(
            onStopRequested: static fn(int $checks): bool => $checks >= 2,
        );
        $pool = new WorkerPool(
            static function () use ($marker): Worker {
                file_put_contents($marker, 'created', LOCK_EX);

                return omnibusRunwireIdleWorker();
            },
            backend: new RunwireWorkerPoolBackend(),
            lifecycle: $lifecycle,
            lifecycleIntervalSeconds: 0.01,
            shutdownGraceSeconds: 0.1,
        );

        $pool->run();

        expect(file_get_contents($marker))->toBe('created')
            ->and($lifecycle->heartbeats)->toBeGreaterThanOrEqual(2)
            ->and($lifecycle->stopChecks)->toBeGreaterThanOrEqual(2);
    } finally {
        if (file_exists($marker)) {
            unlink($marker);
        }
    }
});

test('runwire worker pool maps bounded crash restart exhaustion', function (): void {
    $pool = new WorkerPool(
        static function (): Worker {
            throw new RuntimeException('runwire-crash');
        },
        maximumRestarts: 0,
        restartBackoffSeconds: 0,
        shutdownGraceSeconds: 0.1,
        backend: new RunwireWorkerPoolBackend(),
    );

    expect(fn() => $pool->run())
        ->toThrow(RuntimeException::class, 'exhausted its restart budget');
});

test('runwire worker pool treats clean worker completion as planned recycle', function (): void {
    $counter = tempnam(sys_get_temp_dir(), 'omnibus-runwire-recycle-');
    if ($counter === false) {
        throw new RuntimeException('Unable to allocate Runwire recycle counter.');
    }
    file_put_contents($counter, '0');

    try {
        $pool = new WorkerPool(
            static function () use ($counter): Worker {
                $stream = fopen($counter, 'c+');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open Runwire recycle counter.');
                }
                flock($stream, LOCK_EX);
                $cycle = ((int) stream_get_contents($stream)) + 1;
                ftruncate($stream, 0);
                rewind($stream);
                fwrite($stream, (string) $cycle);
                fflush($stream);
                flock($stream, LOCK_UN);
                fclose($stream);

                if ($cycle > 2) {
                    throw new RuntimeException('end-runwire-recycle-test');
                }

                $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                $transport = new InMemoryTransport($clock);
                $transport->send(new Envelope(new TestCommand((string) $cycle)), 'work');

                return new Worker(
                    new Consumer(
                        $transport,
                        new HandlerInvoker(new HandlerMap([
                            TestCommand::class => static function (): void {},
                        ])),
                        new ExponentialRetryStrategy(),
                        new InMemoryFailureStore(),
                        $clock,
                    ),
                    new WorkerOptions(queue: 'work', maxMessages: 1),
                );
            },
            maximumRestarts: 0,
            restartBackoffSeconds: 0,
            shutdownGraceSeconds: 0.1,
            backend: new RunwireWorkerPoolBackend(),
        );

        expect(fn() => $pool->run())
            ->toThrow(RuntimeException::class, 'exhausted its restart budget');
        expect(file_get_contents($counter))->toBe('3');
    } finally {
        unlink($counter);
    }
});

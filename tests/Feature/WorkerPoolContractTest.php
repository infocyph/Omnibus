<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\NativeWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\RunwireWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Consumer\WorkerPoolBackend;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\RecordingWorkerLifecycle;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

function omnibusMatrixBackend(string $name): WorkerPoolBackend
{
    return match ($name) {
        'native' => new NativeWorkerPoolBackend(),
        'runwire' => new RunwireWorkerPoolBackend(),
        default => throw new InvalidArgumentException('Unknown WorkerPool test backend.'),
    };
}

function omnibusAssertNoWorkerChildren(): void
{
    $status = 0;
    $pid = pcntl_waitpid(-1, $status, WNOHANG);

    expect($pid)->toBe(-1)
        ->and(pcntl_get_last_error())->toBe(PCNTL_ECHILD);
}

test('worker pool backends create and execute workers in the child process', function (string $backend): void {
    $report = tempnam(sys_get_temp_dir(), 'omnibus-pool-matrix-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate a WorkerPool matrix report.');
    }
    unlink($report);
    $parentPid = getmypid();
    if (!is_int($parentPid)) {
        throw new RuntimeException('Unable to resolve the parent PID.');
    }

    try {
        $lifecycle = new RecordingWorkerLifecycle(
            onStopRequested: static function () use ($report): bool {
                if (!is_file($report)) {
                    return false;
                }

                return str_contains((string) file_get_contents($report), 'handled:');
            },
        );
        $pool = new WorkerPool(
            static function () use ($report): Worker {
                $factoryPid = getmypid();
                if (!is_int($factoryPid)) {
                    throw new RuntimeException('Unable to resolve the worker-factory PID.');
                }
                file_put_contents($report, 'factory:' . $factoryPid . PHP_EOL, FILE_APPEND | LOCK_EX);

                $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                $transport = new InMemoryTransport($clock);
                $transport->send(new Envelope(new TestCommand('matrix')), 'work');

                return new Worker(
                    new Consumer(
                        $transport,
                        new HandlerInvoker(new HandlerMap([
                            TestCommand::class => static function () use ($report): void {
                                $handlerPid = getmypid();
                                if (!is_int($handlerPid)) {
                                    throw new RuntimeException('Unable to resolve the handler PID.');
                                }
                                file_put_contents(
                                    $report,
                                    'handled:' . $handlerPid . PHP_EOL,
                                    FILE_APPEND | LOCK_EX,
                                );
                            },
                        ])),
                        new ExponentialRetryStrategy(),
                        new InMemoryFailureStore(),
                        $clock,
                    ),
                    new WorkerOptions(
                        queue: 'work',
                        idleSleepSeconds: 0.001,
                        maxIdleSleepSeconds: 0.001,
                        handleSignals: false,
                    ),
                );
            },
            backend: omnibusMatrixBackend($backend),
            lifecycle: $lifecycle,
            lifecycleIntervalSeconds: 0.01,
            shutdownGraceSeconds: 0.2,
        );

        $pool->run();

        $lines = file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('Unable to read the WorkerPool matrix report.');
        }
        $factory = array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, 'factory:'),
        ));
        $handled = array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, 'handled:'),
        ));
        $factoryPid = isset($factory[0]) ? (int) substr($factory[0], strlen('factory:')) : 0;
        $handlerPid = isset($handled[0]) ? (int) substr($handled[0], strlen('handled:')) : 0;

        expect($factoryPid)->toBeGreaterThan(0)
            ->and($handlerPid)->toBe($factoryPid)
            ->and($factoryPid)->not->toBe($parentPid);

        omnibusAssertNoWorkerChildren();
    } finally {
        if (is_file($report)) {
            unlink($report);
        }
    }
})->with(['native', 'runwire']);

test('worker pool backends reap crashed children before propagating exhaustion', function (string $backend): void {
    $pool = new WorkerPool(
        static function (): Worker {
            throw new RuntimeException('matrix-crash');
        },
        maximumRestarts: 0,
        restartBackoffSeconds: 0,
        shutdownGraceSeconds: 0.1,
        backend: omnibusMatrixBackend($backend),
    );

    expect(fn() => $pool->run())
        ->toThrow(RuntimeException::class, 'exhausted its restart budget');
    omnibusAssertNoWorkerChildren();
})->with(['native', 'runwire']);

test('worker pool backends service lifecycle and cancel crash restarts during backoff', function (
    string $backend,
    int $concurrency,
    bool $failLifecycle,
): void {
    $report = tempnam(sys_get_temp_dir(), 'omnibus-backoff-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate backoff report.');
    }
    $started = hrtime(true);
    $beats = [];
    $lifecycle = new RecordingWorkerLifecycle(
        onHeartbeat: static function () use (&$beats): void {
            $beats[] = hrtime(true);
        },
        onStopRequested: static function () use ($started, $failLifecycle): bool {
            if ((hrtime(true) - $started) / 1_000_000_000 < 0.2) {
                return false;
            }
            if ($failLifecycle) {
                throw new RuntimeException('backoff lifecycle failure');
            }

            return true;
        },
    );
    try {
        $pool = new WorkerPool(
            static function (int $slot) use ($report): Worker {
                file_put_contents($report, $slot . PHP_EOL, FILE_APPEND | LOCK_EX);
                if ($slot === 0) {
                    throw new RuntimeException('backoff crash');
                }
                pcntl_signal(SIGTERM, SIG_IGN);
                while (true) {
                    usleep(1_000);
                }
            },
            concurrency: $concurrency,
            maximumRestarts: 2,
            restartBackoffSeconds: 1.0,
            shutdownGraceSeconds: 0.05,
            backend: omnibusMatrixBackend($backend),
            lifecycle: $lifecycle,
            lifecycleIntervalSeconds: 0.01,
        );
        if ($failLifecycle) {
            expect(fn() => $pool->run())->toThrow(RuntimeException::class, 'backoff lifecycle failure');
        } else {
            $pool->run();
        }
        expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.8)
            ->and(count($beats))->toBeGreaterThan(5)
            ->and(file($report, FILE_IGNORE_NEW_LINES))->toHaveCount($concurrency);
        for ($index = 1; $index < count($beats); $index++) {
            expect(($beats[$index] - $beats[$index - 1]) / 1_000_000_000)->toBeLessThan(0.3);
        }
        omnibusAssertNoWorkerChildren();
    } finally {
        unlink($report);
    }
})->with(['native', 'runwire'])->with([1, 2])->with([false, true]);

test('worker pool backends restart pending slots and exhaust the crash budget', function (string $backend): void {
    $report = tempnam(sys_get_temp_dir(), 'omnibus-restarts-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate restart report.');
    }
    try {
        $pool = new WorkerPool(
            static function () use ($report): Worker {
                file_put_contents($report, 'crash' . PHP_EOL, FILE_APPEND | LOCK_EX);
                throw new RuntimeException('scheduled crash');
            },
            maximumRestarts: 2,
            restartBackoffSeconds: 0.02,
            shutdownGraceSeconds: 0.1,
            backend: omnibusMatrixBackend($backend),
        );
        expect(fn() => $pool->run())->toThrow(RuntimeException::class, 'exhausted its restart budget');
        expect(file($report))->toHaveCount(3);
        omnibusAssertNoWorkerChildren();
    } finally {
        unlink($report);
    }
})->with(['native', 'runwire']);

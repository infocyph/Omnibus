<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\NativeWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\RecordingWorkerLifecycle;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;

test('WorkerPool constructs DBLayer resources inside the forked child factory', function (): void {
    if (!function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
        throw new RuntimeException('WorkerPool fork-safety integration requires ext-pcntl and ext-posix.');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('WorkerPool fork-safety integration requires pdo_sqlite.');
    }

    $database = tempnam(sys_get_temp_dir(), 'omnibus-fork-safe-');
    $report = tempnam(sys_get_temp_dir(), 'omnibus-fork-report-');
    if ($database === false || $report === false) {
        throw new RuntimeException('Unable to allocate WorkerPool fork-safety fixtures.');
    }
    unlink($report);

    $configuration = ['driver' => 'sqlite', 'database' => $database];
    $parentPid = getmypid();
    if (!is_int($parentPid)) {
        throw new RuntimeException('Unable to resolve the parent PID.');
    }

    try {
        $seed = new Connection(ConnectionConfig::fromArray($configuration));
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $seed->statement($statement);
        }
        $seedTransport = new DBLayerTransport($seed, TestSerializer::make(), new SystemClock());
        $seedTransport->send(new Envelope(new TestCommand('child-owned-db')), 'fork-safe');
        $seed->disconnect();
        unset($seedTransport, $seed);

        $lifecycle = new RecordingWorkerLifecycle(
            onStopRequested: static function () use ($report): bool {
                if (!is_file($report)) {
                    return false;
                }

                return str_contains((string) file_get_contents($report), 'handled:');
            },
        );
        $pool = new WorkerPool(
            static function () use ($configuration, $report): Worker {
                $factoryPid = getmypid();
                if (!is_int($factoryPid)) {
                    throw new RuntimeException('Unable to resolve the child factory PID.');
                }
                file_put_contents($report, 'factory:' . $factoryPid . PHP_EOL, FILE_APPEND | LOCK_EX);

                $connection = new Connection(ConnectionConfig::fromArray($configuration));
                $transport = new DBLayerTransport($connection, TestSerializer::make(), new SystemClock());

                return new Worker(
                    new Consumer(
                        $transport,
                        new HandlerInvoker(new HandlerMap([
                            TestCommand::class => static function () use ($report): void {
                                $handlerPid = getmypid();
                                if (!is_int($handlerPid)) {
                                    throw new RuntimeException('Unable to resolve the child handler PID.');
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
                        new SystemClock(),
                    ),
                    new WorkerOptions(
                        queue: 'fork-safe',
                        idleSleepSeconds: 0.001,
                        maxIdleSleepSeconds: 0.001,
                        handleSignals: false,
                    ),
                );
            },
            backend: new NativeWorkerPoolBackend(),
            lifecycle: $lifecycle,
            lifecycleIntervalSeconds: 0.01,
            shutdownGraceSeconds: 0.2,
        );

        $pool->run();

        $lines = file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('Unable to read WorkerPool fork-safety report.');
        }
        $factoryLine = current(array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, 'factory:'),
        )));
        $handledLine = current(array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, 'handled:'),
        )));
        $factoryPid = is_string($factoryLine) ? (int) substr($factoryLine, strlen('factory:')) : 0;
        $handlerPid = is_string($handledLine) ? (int) substr($handledLine, strlen('handled:')) : 0;

        $verification = new Connection(ConnectionConfig::fromArray($configuration));
        $verificationTransport = new DBLayerTransport(
            $verification,
            TestSerializer::make(),
            new SystemClock(),
        );

        expect($factoryPid)->toBeGreaterThan(0)
            ->and($handlerPid)->toBe($factoryPid)
            ->and($factoryPid)->not->toBe($parentPid)
            ->and($verificationTransport->size('fork-safe'))->toBe(0);

        $verification->disconnect();
    } finally {
        foreach ([$database, $database . '-journal', $database . '-shm', $database . '-wal', $report] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

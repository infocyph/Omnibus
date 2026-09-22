<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\NativeWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\RunwireWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Consumer\WorkerPoolBackend;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InMemoryTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class WorkerPoolBenchmarkMessage
{
    public function __construct(public int $sequence) {}
}

final class WorkerPoolBenchmarkLifecycle implements WorkerLifecycle
{
    /** @var \Closure():bool */
    private \Closure $stop;

    /** @param callable():bool $stop */
    public function __construct(callable $stop)
    {
        $this->stop = $stop(...);
    }

    public function heartbeat(): void {}

    public function stopRequested(): bool
    {
        return ($this->stop)();
    }
}

/** @return array{wall_ms:float,parent_cpu_ms:float,parent_cpu_ratio:float} */
function omnibusWorkerPoolMeasure(callable $operation): array
{
    $beforeUsage = getrusage();
    $started = hrtime(true);
    $operation();
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $afterUsage = getrusage();
    $cpuMs = (omnibusWorkerPoolCpuSeconds($afterUsage) - omnibusWorkerPoolCpuSeconds($beforeUsage)) * 1_000;

    return [
        'wall_ms' => $elapsed,
        'parent_cpu_ms' => $cpuMs,
        'parent_cpu_ratio' => $elapsed > 0.0 ? $cpuMs / $elapsed : 0.0,
    ];
}

/** @param array<string, int> $usage */
function omnibusWorkerPoolCpuSeconds(array $usage): float
{
    return ($usage['ru_utime.tv_sec'] ?? 0)
        + (($usage['ru_utime.tv_usec'] ?? 0) / 1_000_000)
        + ($usage['ru_stime.tv_sec'] ?? 0)
        + (($usage['ru_stime.tv_usec'] ?? 0) / 1_000_000);
}

function omnibusWorkerPoolBackend(string $name): WorkerPoolBackend
{
    return match ($name) {
        'native' => new NativeWorkerPoolBackend(),
        'runwire' => new RunwireWorkerPoolBackend(),
        default => throw new InvalidArgumentException('Unknown WorkerPool benchmark backend.'),
    };
}

function omnibusWorkerPoolConsumer(int $messages, ?string $report = null): Consumer
{
    $clock = new SystemClock();
    $transport = new InMemoryTransport($clock);
    for ($sequence = 0; $sequence < $messages; $sequence++) {
        $transport->send(new Envelope(new WorkerPoolBenchmarkMessage($sequence)), 'benchmark');
    }

    return new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            WorkerPoolBenchmarkMessage::class => static function () use ($report): void {
                if ($report !== null) {
                    file_put_contents($report, 'h', FILE_APPEND | LOCK_EX);
                }
            },
        ])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
    );
}

function omnibusWorkerPoolFileSize(string $path): int
{
    clearstatcache(true, $path);

    return is_file($path) ? (int) filesize($path) : 0;
}

/** @return array{measurement:array{wall_ms:float,parent_cpu_ms:float,parent_cpu_ratio:float},handled:int} */
function omnibusWorkerPoolProcessing(string $backend, int $concurrency, int $messages): array
{
    $report = tempnam(sys_get_temp_dir(), 'omnibus-pool-benchmark-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate WorkerPool benchmark report.');
    }
    file_put_contents($report, '');

    try {
        $expected = $concurrency * $messages;
        $lifecycle = new WorkerPoolBenchmarkLifecycle(
            static fn(): bool => omnibusWorkerPoolFileSize($report) >= $expected,
        );
        $measurement = omnibusWorkerPoolMeasure(static function () use (
            $backend,
            $concurrency,
            $messages,
            $report,
            $lifecycle,
        ): void {
            $pool = new WorkerPool(
                static fn(): Worker => new Worker(
                    omnibusWorkerPoolConsumer($messages, $report),
                    new WorkerOptions(
                        queue: 'benchmark',
                        idleSleepSeconds: 0.001,
                        maxIdleSleepSeconds: 0.001,
                        handleSignals: false,
                    ),
                ),
                concurrency: $concurrency,
                maximumRestarts: 0,
                restartBackoffSeconds: 0,
                shutdownGraceSeconds: 0.25,
                backend: omnibusWorkerPoolBackend($backend),
                lifecycle: $lifecycle,
                lifecycleIntervalSeconds: 0.005,
            );
            $pool->run();
        });

        return [
            'measurement' => $measurement,
            'handled' => omnibusWorkerPoolFileSize($report),
        ];
    } finally {
        if (is_file($report)) {
            unlink($report);
        }
    }
}

/** @return array{measurement:array{wall_ms:float,parent_cpu_ms:float,parent_cpu_ratio:float},handled_cycles:int,memory_growth_bytes:int} */
function omnibusWorkerPoolRecycle(string $backend, int $cycles): array
{
    $report = tempnam(sys_get_temp_dir(), 'omnibus-pool-recycle-');
    if ($report === false) {
        throw new RuntimeException('Unable to allocate WorkerPool recycle report.');
    }
    file_put_contents($report, '');

    try {
        $baseline = memory_get_usage(true);
        $lifecycle = new WorkerPoolBenchmarkLifecycle(
            static fn(): bool => omnibusWorkerPoolFileSize($report) >= $cycles,
        );
        $measurement = omnibusWorkerPoolMeasure(static function () use ($backend, $report, $lifecycle): void {
            $pool = new WorkerPool(
                static fn(): Worker => new Worker(
                    omnibusWorkerPoolConsumer(1, $report),
                    new WorkerOptions(
                        queue: 'benchmark',
                        maxMessages: 1,
                        handleSignals: false,
                    ),
                ),
                maximumRestarts: 0,
                restartBackoffSeconds: 0,
                shutdownGraceSeconds: 0.25,
                backend: omnibusWorkerPoolBackend($backend),
                lifecycle: $lifecycle,
                lifecycleIntervalSeconds: 0.005,
            );
            $pool->run();
        });

        return [
            'measurement' => $measurement,
            'handled_cycles' => omnibusWorkerPoolFileSize($report),
            'memory_growth_bytes' => memory_get_usage(true) - $baseline,
        ];
    } finally {
        if (is_file($report)) {
            unlink($report);
        }
    }
}

/** @return array{wall_ms:float,parent_cpu_ms:float,parent_cpu_ratio:float} */
function omnibusWorkerPoolIdle(string $backend, float $seconds): array
{
    $deadline = hrtime(true) + (int) round($seconds * 1_000_000_000);
    $lifecycle = new WorkerPoolBenchmarkLifecycle(
        static fn(): bool => hrtime(true) >= $deadline,
    );

    return omnibusWorkerPoolMeasure(static function () use ($backend, $lifecycle): void {
        $pool = new WorkerPool(
            static fn(): Worker => new Worker(
                omnibusWorkerPoolConsumer(0),
                new WorkerOptions(
                    queue: 'benchmark',
                    idleSleepSeconds: 0.01,
                    maxIdleSleepSeconds: 0.01,
                    handleSignals: false,
                ),
            ),
            maximumRestarts: 0,
            restartBackoffSeconds: 0,
            shutdownGraceSeconds: 0.25,
            backend: omnibusWorkerPoolBackend($backend),
            lifecycle: $lifecycle,
            lifecycleIntervalSeconds: 0.005,
        );
        $pool->run();
    });
}

$messages = filter_var($argv[1] ?? 100, FILTER_VALIDATE_INT);
$cycles = filter_var($argv[2] ?? 10, FILTER_VALIDATE_INT);
if (!is_int($messages) || $messages < 1 || $messages > 10_000) {
    throw new InvalidArgumentException('Messages must be between 1 and 10000.');
}
if (!is_int($cycles) || $cycles < 1 || $cycles > 1_000) {
    throw new InvalidArgumentException('Recycle cycles must be between 1 and 1000.');
}
if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    throw new RuntimeException('WorkerPool benchmark requires ext-pcntl and ext-posix.');
}

$direct = omnibusWorkerPoolMeasure(static function () use ($messages): void {
    omnibusWorkerPoolConsumer($messages)->run('benchmark', $messages);
});
$singleWorker = omnibusWorkerPoolMeasure(static function () use ($messages): void {
    (new Worker(
        omnibusWorkerPoolConsumer($messages),
        new WorkerOptions(
            queue: 'benchmark',
            prefetch: min(100, $messages),
            maxMessages: $messages,
            idleSleepSeconds: 0,
            maxIdleSleepSeconds: 0,
            handleSignals: false,
        ),
    ))->run();
});

fwrite(STDOUT, json_encode([
    'scope' => 'Omnibus worker/process component benchmark; not application RPM',
    'php' => PHP_VERSION,
    'messages_per_worker' => $messages,
    'recycle_target' => $cycles,
    'consumer_direct' => $direct,
    'single_worker' => $singleWorker,
    'native_pool_1' => omnibusWorkerPoolProcessing('native', 1, $messages),
    'native_pool_2' => omnibusWorkerPoolProcessing('native', 2, $messages),
    'runwire_pool_1' => omnibusWorkerPoolProcessing('runwire', 1, $messages),
    'runwire_pool_2' => omnibusWorkerPoolProcessing('runwire', 2, $messages),
    'native_idle_100ms' => omnibusWorkerPoolIdle('native', 0.1),
    'runwire_idle_100ms' => omnibusWorkerPoolIdle('runwire', 0.1),
    'native_recycle_soak' => omnibusWorkerPoolRecycle('native', $cycles),
    'runwire_recycle_soak' => omnibusWorkerPoolRecycle('runwire', $cycles),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

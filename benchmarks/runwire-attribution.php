<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class AttributionMessage
{
    public function __construct(public int $value) {}
}

/** @param list<float> $samples */
function percentile(array $samples, float $quantile): float
{
    sort($samples, SORT_NUMERIC);

    return $samples[max(0, (int) ceil(count($samples) * $quantile) - 1)];
}

/** @return array{user:float,system:float} */
function processCpu(): array
{
    $usage = getrusage();

    return [
        'user' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000,
        'system' => $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000,
    ];
}

$iterations = filter_var($argv[1] ?? 20_000, FILTER_VALIDATE_INT);
if (!is_int($iterations) || $iterations < 100 || $iterations > 1_000_000) {
    throw new InvalidArgumentException('Attribution iterations must be between 100 and 1000000.');
}
$output = $argv[2] ?? 'build/runwire-attribution.json';
$binding = new RunwireBinding();
$message = new AttributionMessage(42);
$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker(new HandlerMap([
        AttributionMessage::class => static fn(AttributionMessage $value): int => $value->value,
    ])))]),
    $binding,
);
$runtime = RuntimeContext::fromCapabilities(
    new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
    'attribution-persistent-host',
    workerSlot: 0,
    generation: 1,
);
$scenarios = ['direct', 'request-only', 'host-only', 'bound-noop', 'bound-dispatch'];
$run = static function (string $scenario) use ($bus, $runtime, $message): int {
    if ($scenario === 'direct') {
        return $bus->dispatch($message)->last(HandledStamp::class)?->result;
    }

    $request = RequestContext::create($runtime);
    try {
        if ($scenario === 'request-only') {
            return 42;
        }
        if ($scenario === 'host-only') {
            return $bus->dispatch($message)->last(HandledStamp::class)?->result;
        }
        if ($scenario === 'bound-noop') {
            return $bus->withRunwire($runtime, static fn(): int => 42, $request);
        }

        return $bus->withRunwire(
            $runtime,
            static fn(): mixed => $bus->dispatch($message)->last(HandledStamp::class)?->result,
            $request,
        );
    } finally {
        $request->complete();
    }
};

$results = [];
for ($trial = 0; $trial < 3; $trial++) {
    // Rotate scenario order across trials so a hotter process does not always benefit the same variant.
    $ordered = array_merge(array_slice($scenarios, $trial), array_slice($scenarios, 0, $trial));
    foreach ($ordered as $scenario) {
        for ($warmup = 0; $warmup < 1_000; $warmup++) {
            if ($run($scenario) !== 42) {
                throw new RuntimeException('Warmup returned incorrect result.');
            }
        }

        gc_collect_cycles();
        $beforeMemory = memory_get_usage(true);
        $beforeCpu = processCpu();
        $samples = [];
        $started = hrtime(true);
        for ($index = 0; $index < $iterations; $index++) {
            $operationStarted = hrtime(true);
            if ($run($scenario) !== 42) {
                throw new RuntimeException('Profiled operation returned incorrect result.');
            }
            $samples[] = (hrtime(true) - $operationStarted) / 1_000;
        }
        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        $afterCpu = processCpu();
        if ($binding->runtime() !== null || $binding->request() !== null) {
            throw new RuntimeException('Runwire binding leaked host context after a scenario.');
        }
        $results[] = [
            'trial' => $trial + 1,
            'scenario' => $scenario,
            'operations' => $iterations,
            'seconds' => $seconds,
            'successful_ops_per_second' => $iterations / $seconds,
            'latency_us' => [
                'p50' => percentile($samples, 0.5),
                'p95' => percentile($samples, 0.95),
                'p99' => percentile($samples, 0.99),
            ],
            'php_allocated_memory_growth_bytes' => memory_get_usage(true) - $beforeMemory,
            'cpu_seconds' => [
                'user' => $afterCpu['user'] - $beforeCpu['user'],
                'system' => $afterCpu['system'] - $beforeCpu['system'],
            ],
        ];
    }
}
$report = [
    'kind' => 'single-process component attribution only; not host HTTP capacity or release gate',
    'php' => PHP_VERSION,
    'host_runtime_reused' => true,
    'new_request_per_operation' => true,
    'thread_concurrency' => 1,
    'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local',
    'stable' => false,
    'scenarios' => [
        'direct' => 'dispatch, no host Runwire request',
        'request-only' => 'fresh Runwire request create + complete, no dispatch',
        'host-only' => 'fresh request create + complete and unbound dispatch',
        'bound-noop' => 'fresh request and Omnibus binding with a no-op callback',
        'bound-dispatch' => 'fresh request and Omnibus binding around synchronous dispatch',
    ],
    'trials' => $results,
];
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0777, true) && !is_dir(dirname($output))) {
    throw new RuntimeException('Failed to create attribution output directory.');
}
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
fwrite(STDOUT, "runwire-attribution-ok\n");

<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class MiddlewareBenchmarkMessage
{
    public function __construct(public int $value) {}
}

final class PassthroughBenchmarkMiddleware implements HandlerMiddleware
{
    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed {
        return $next($message, $envelope, $context);
    }
}

/** @return array{operations_per_second:float,ns_per_operation:float,peak_memory_bytes:int,memory_growth_bytes:int} */
function benchmarkMiddleware(int $iterations, callable $operation): array
{
    for ($index = 0; $index < min(1_000, $iterations); $index++) {
        $operation();
    }

    gc_collect_cycles();
    $memory = memory_get_usage(true);
    $started = hrtime(true);
    for ($index = 0; $index < $iterations; $index++) {
        $operation();
    }
    $elapsed = hrtime(true) - $started;

    return [
        'operations_per_second' => $iterations / ($elapsed / 1_000_000_000),
        'ns_per_operation' => $elapsed / $iterations,
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'memory_growth_bytes' => memory_get_usage(true) - $memory,
    ];
}

$iterations = filter_var($argv[1] ?? 100_000, FILTER_VALIDATE_INT);
if (!is_int($iterations) || $iterations < 1 || $iterations > 10_000_000) {
    throw new InvalidArgumentException('Iterations must be between 1 and 10000000.');
}

$message = new MiddlewareBenchmarkMessage(42);
$envelope = new Envelope($message);
$context = new HandlerContext('benchmark');
$handlers = new HandlerMap([
    MiddlewareBenchmarkMessage::class => static fn(MiddlewareBenchmarkMessage $item): int => $item->value,
]);
$results = [
    'handler_map_direct' => benchmarkMiddleware(
        $iterations,
        static fn() => ($handlers->for($message))($message, $envelope, 'benchmark'),
    ),
];

foreach ([0, 1, 3, 5, 10] as $count) {
    $middleware = $count === 0
        ? []
        : array_map(
            static fn(): HandlerMiddleware => new PassthroughBenchmarkMiddleware(),
            range(1, $count),
        );
    $invoker = new HandlerInvoker($handlers, $middleware);
    $results['invoker_' . $count] = benchmarkMiddleware(
        $iterations,
        static fn() => $invoker->invoke($message, $envelope, $context),
    );
}

$sync = new SyncTransport(new HandlerInvoker($handlers, [new PassthroughBenchmarkMiddleware()]));
$results['sync_transport_1'] = benchmarkMiddleware(
    $iterations,
    static fn() => $sync->send($envelope, 'benchmark'),
);

$consumerIterations = min($iterations, 10_000);
$clock = new SystemClock();
$transport = new InMemoryTransport($clock);
$consumer = new Consumer(
    $transport,
    new HandlerInvoker($handlers, [new PassthroughBenchmarkMiddleware()]),
    new ExponentialRetryStrategy(initialDelaySeconds: 0),
    new InMemoryFailureStore(),
    $clock,
);
$results['consumer_1'] = benchmarkMiddleware(
    $consumerIterations,
    static function () use ($consumer, $envelope, $transport): void {
        $transport->send($envelope, 'benchmark');
        $consumer->run('benchmark');
    },
);

fwrite(STDOUT, json_encode([
    'iterations' => $iterations,
    'consumer_iterations' => $consumerIterations,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

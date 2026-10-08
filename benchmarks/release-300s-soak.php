<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ReleaseSoakMessage
{
    public function __construct(public int $key) {}
}

$seconds = filter_var($argv[1] ?? 300, FILTER_VALIDATE_INT);
if (!is_int($seconds) || $seconds < 1 || $seconds > 900) {
    throw new InvalidArgumentException('Soak duration must be between 1 and 900 seconds.');
}

$clock = new SystemClock();
$binding = new RunwireBinding();
$transport = new InMemoryTransport($clock);
$effects = [];
$invoker = new HandlerInvoker(new HandlerMap([
    ReleaseSoakMessage::class => static function (ReleaseSoakMessage $message) use (&$effects): void {
        $effects[$message->key] = true;
    },
]));
$bus = new MessageBus(
    new RouteMap([ReleaseSoakMessage::class => new Route('memory', 'soak')]),
    new TransportRegistry(['memory' => $transport]),
    $binding,
);
$failures = new InMemoryFailureStore($clock);
$consumer = new Consumer(
    $transport,
    $invoker,
    new ExponentialRetryStrategy(initialDelaySeconds: 0.0),
    $failures,
    $clock,
    new DirectExecutionScope(),
    $binding,
);
$runtime = RuntimeContext::fromCapabilities(
    new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
    'omnibus-release-soak',
    workerSlot: 0,
    generation: 1,
);
$started = hrtime(true);
$iterations = 0;
$maxDepth = 0;
$warmMemory = null;
$peakMemory = memory_get_usage(true);

do {
    $index = $iterations % 32;
    $request = RequestContext::create($runtime);

    try {
        $result = $bus->withRunwire($runtime, static function () use ($bus, $consumer, $index): mixed {
            $bus->dispatch(new ReleaseSoakMessage($index));

            return $consumer->run('soak');
        }, $request);
    } finally {
        $request->complete();
    }

    if ($result->received !== 1 || $result->succeeded !== 1 || $result->failed !== 0
        || $binding->runtime() !== null || $binding->request() !== null) {
        throw new RuntimeException('Persistent request/consumer lifecycle failed to settle safely.');
    }

    $iterations++;
    if ($iterations % 1_000 === 0) {
        gc_collect_cycles();
        $maxDepth = max($maxDepth, $transport->size('soak'));
        $memory = memory_get_usage(true);
        $peakMemory = max($peakMemory, $memory);
        if ($warmMemory === null && (hrtime(true) - $started) >= 1_000_000_000) {
            $warmMemory = $memory;
        }
    }
} while ((hrtime(true) - $started) < $seconds * 1_000_000_000);

$warmMemory ??= memory_get_usage(true);
$growth = max(0, memory_get_usage(true) - $warmMemory);
if ($iterations < 32 || count($effects) !== 32 || $transport->size('soak') !== 0
    || $failures->all() !== [] || $growth > 16 * 1024 * 1024 || $maxDepth > 0) {
    throw new RuntimeException('Persistent-host soak exceeded memory/queue or idempotency safety limits.');
}

fwrite(STDOUT, json_encode([
    'php' => PHP_VERSION,
    'elapsed_seconds' => round((hrtime(true) - $started) / 1_000_000_000, 3),
    'completed_jobs' => $iterations,
    'unique_business_side_effects' => count($effects),
    'final_queue_depth' => $transport->size('soak'),
    'memory_growth_after_warmup_bytes' => $growth,
    'sampled_peak_php_allocated_bytes' => $peakMemory,
    'scope' => 'Single persistent CLI runtime and process-local queue; no claim of cross-process RSS or RPM release certification.',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

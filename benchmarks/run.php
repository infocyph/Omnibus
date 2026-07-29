<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class BenchmarkMessage
{
    public function __construct(public int $value) {}
}

/**
 * @return array{iterations:int,total_ms:float,operations_per_second:float,ns_per_operation:float}
 */
function measure(int $iterations, callable $operation): array
{
    for ($index = 0; $index < min(1_000, $iterations); $index++) {
        $operation();
    }

    $started = hrtime(true);
    for ($index = 0; $index < $iterations; $index++) {
        $operation();
    }
    $elapsed = hrtime(true) - $started;

    return [
        'iterations' => $iterations,
        'total_ms' => $elapsed / 1_000_000,
        'operations_per_second' => $iterations / ($elapsed / 1_000_000_000),
        'ns_per_operation' => $elapsed / $iterations,
    ];
}

/**
 * @return array{iterations:int,total_ms:float,operations_per_second:float,ns_per_operation:float}
 */
function measureWithoutWarmup(int $iterations, callable $operation): array
{
    $started = hrtime(true);
    for ($index = 0; $index < $iterations; $index++) {
        $operation();
    }
    $elapsed = hrtime(true) - $started;

    return [
        'iterations' => $iterations,
        'total_ms' => $elapsed / 1_000_000,
        'operations_per_second' => $iterations / ($elapsed / 1_000_000_000),
        'ns_per_operation' => $elapsed / $iterations,
    ];
}

$iterations = filter_var($argv[1] ?? 100_000, FILTER_VALIDATE_INT);
if (!is_int($iterations) || $iterations < 1 || $iterations > 10_000_000) {
    throw new InvalidArgumentException('Iterations must be between 1 and 10000000.');
}

$message = new BenchmarkMessage(42);
$handlers = new HandlerMap([
    BenchmarkMessage::class => static fn(BenchmarkMessage $item): int => $item->value,
]);
$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport($handlers)]),
);
$zeroListeners = new EventDispatcher(new ListenerMap());
$oneListener = new EventDispatcher(new ListenerMap([
    BenchmarkMessage::class => [static fn(BenchmarkMessage $item): int => $item->value],
]));
$multipleListeners = new EventDispatcher(new ListenerMap([
    BenchmarkMessage::class => [
        static fn(BenchmarkMessage $item): int => $item->value,
        static fn(BenchmarkMessage $item): int => $item->value + 1,
        static fn(BenchmarkMessage $item): int => $item->value + 2,
    ],
]));
$serializer = new JsonEnvelopeSerializer(
    new MessageCodecRegistry([
        new CallbackMessageCodec(
            'benchmark.v1',
            BenchmarkMessage::class,
            static fn(BenchmarkMessage $item): array => ['value' => $item->value],
            static fn(array $data): BenchmarkMessage => new BenchmarkMessage((int) ($data['value'] ?? 0)),
        ),
    ]),
    new StampCodecRegistry(CoreStampCodecs::all()),
);
$encoded = $serializer->encode(new Envelope($message));
$clock = new SystemClock();
$memory = new InMemoryTransport($clock);
$failures = new InMemoryFailureStore();
$failure = FailedMessage::decoded(
    'benchmark-failure',
    'benchmark',
    new Envelope($message),
    1,
    $clock->now(),
    RuntimeException::class,
    'benchmark',
);
$connection = new Connection(ConnectionConfig::fromArray([
    'driver' => 'sqlite',
    'database' => ':memory:',
]));
foreach (QueueSchema::statements('sqlite') as $statement) {
    $connection->statement($statement);
}
$database = new DBLayerTransport($connection, $serializer, $clock);
$durableIterations = min($iterations, 10_000);
$retryTransport = new InMemoryTransport($clock);
$retryConsumer = new Consumer(
    $retryTransport,
    new HandlerMap([
        BenchmarkMessage::class => static fn() => throw new RuntimeException('benchmark'),
    ]),
    new ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: 0),
    new InMemoryFailureStore(),
    $clock,
);

$results = [
    'sync_dispatch' => measure($iterations, static fn() => $bus->dispatch($message)),
    'event_zero_listeners' => measure($iterations, static fn() => $zeroListeners->dispatch($message)),
    'event_one_listener' => measure($iterations, static fn() => $oneListener->dispatch($message)),
    'event_three_listeners' => measure($iterations, static fn() => $multipleListeners->dispatch($message)),
    'json_round_trip' => measure(
        $iterations,
        static fn() => $serializer->decode($serializer->encode(new Envelope($message))),
    ),
    'json_decode' => measure($iterations, static fn() => $serializer->decode($encoded)),
    'in_memory_lifecycle' => measure($iterations, static function () use ($memory, $message): void {
        $memory->send(new Envelope($message), 'benchmark');
        $reservation = [...$memory->receive('benchmark')][0];
        $memory->acknowledge($reservation);
    }),
    'failure_store_add' => measure($iterations, static fn() => $failures->add($failure)),
    'consumer_retry_terminal' => measure($iterations, static function () use (
        $retryTransport,
        $retryConsumer,
        $message,
    ): void {
        $retryTransport->send(new Envelope($message), 'benchmark');
        $retryConsumer->run('benchmark');
        $retryConsumer->run('benchmark');
    }),
    'dblayer_sqlite_enqueue' => measure(
        $durableIterations,
        static fn() => $database->send(new Envelope($message), 'benchmark'),
    ),
    'dblayer_sqlite_receive_ack_100' => measureWithoutWarmup(
        max(1, intdiv($durableIterations, 100)),
        static function () use ($database): void {
            foreach ($database->receive('benchmark', 100) as $reservation) {
                $database->acknowledge($reservation);
            }
        },
    ),
];

fwrite(STDOUT, json_encode([
    'scope' => 'component-only; not application RPM',
    'php' => PHP_VERSION,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

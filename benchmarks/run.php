<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
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

$results = [
    'sync_dispatch' => measure($iterations, static fn() => $bus->dispatch($message)),
    'event_zero_listeners' => measure($iterations, static fn() => $zeroListeners->dispatch($message)),
    'event_one_listener' => measure($iterations, static fn() => $oneListener->dispatch($message)),
    'json_round_trip' => measure(
        $iterations,
        static fn() => $serializer->decode($serializer->encode(new Envelope($message))),
    ),
    'json_decode' => measure($iterations, static fn() => $serializer->decode($encoded)),
];

fwrite(STDOUT, json_encode([
    'scope' => 'component-only; not application RPM',
    'php' => PHP_VERSION,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

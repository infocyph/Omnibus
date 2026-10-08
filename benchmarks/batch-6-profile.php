<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

class Batch6ProfileMessage
{
    public function __construct(public string $value) {}
}

/** @return array{operations:int,operations_per_second:float,ns_per_operation:float,allocated_growth_bytes:int,peak_allocated_bytes:int} */
function batch6Profile(int $operations, callable $work): array
{
    for ($i = 0; $i < min(1_000, $operations); $i++) {
        $work();
    }

    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $before = memory_get_usage(true);
    $started = hrtime(true);
    for ($i = 0; $i < $operations; $i++) {
        $work();
    }
    $elapsed = max(1, hrtime(true) - $started);

    return [
        'operations' => $operations,
        'operations_per_second' => round($operations * 1_000_000_000 / $elapsed, 2),
        'ns_per_operation' => round($elapsed / $operations, 2),
        'allocated_growth_bytes' => memory_get_usage(true) - $before,
        'peak_allocated_bytes' => memory_get_peak_usage(true),
    ];
}

$iterations = filter_var($argv[1] ?? 50_000, FILTER_VALIDATE_INT);
$variants = filter_var($argv[2] ?? 256, FILTER_VALIDATE_INT);
if (!is_int($iterations) || $iterations < 1_000 || $iterations > 1_000_000
    || !is_int($variants) || $variants < 1 || $variants > 10_000) {
    throw new InvalidArgumentException('Expected 1000..1000000 iterations and 1..10000 distinct classes to inspect.');
}

$message = new Batch6ProfileMessage('bounded-safe-json');
$envelope = new Envelope($message);
$codec = new CallbackMessageCodec(
    'batch6.message',
    Batch6ProfileMessage::class,
    static fn(Batch6ProfileMessage $item): array => ['value' => $item->value],
    static fn(array $data): Batch6ProfileMessage => new Batch6ProfileMessage((string) $data['value']),
);
$serializer = new JsonEnvelopeSerializer(
    new MessageCodecRegistry([$codec]),
    new StampCodecRegistry(CoreStampCodecs::all()),
);
$wire = $serializer->encode($envelope);
if ($serializer->decode($wire)->message->value !== $message->value) {
    throw new RuntimeException('Serializer profile did not preserve the payload.');
}

$route = new Route();
$routes = new RouteMap([Batch6ProfileMessage::class => $route]);
$handlers = new HandlerMap([Batch6ProfileMessage::class => static fn(Batch6ProfileMessage $item): string => $item->value]);
$listeners = new ListenerMap([Batch6ProfileMessage::class => [static function (object $event): void {}]]);

$measurements = [
    'codec_validate' => batch6Profile($iterations, static fn(): array => $codec->encode($message)),
    'envelope_encode' => batch6Profile($iterations, static fn(): string => $serializer->encode($envelope)),
    'envelope_decode' => batch6Profile($iterations, static fn(): Envelope => $serializer->decode($wire)),
    'route_warm' => batch6Profile($iterations, static fn(): Route => $routes->for($message)),
    'handler_warm' => batch6Profile($iterations, static fn(): callable => $handlers->for($message)),
    'listener_warm' => batch6Profile($iterations, static fn(): iterable => $listeners->getListenersForEvent($message)),
];

$objects = [];
foreach (get_declared_classes() as $type) {
    $reflection = new ReflectionClass($type);
    if (!$reflection->isInstantiable() || $reflection->isAnonymous()) {
        continue;
    }
    try {
        $objects[] = $reflection->newInstanceWithoutConstructor();
    } catch (Throwable) {
        continue;
    }
    if (count($objects) >= $variants) {
        break;
    }
}
if (count($objects) < 8) {
    throw new RuntimeException('Not enough distinct loadable classes for lookup profiling.');
}
$count = count($objects);
$registrations = [];
foreach ($objects as $item) {
    $registrations[$item::class] = static fn(object $event): string => $event::class;
}

$dynamicRoutes = new RouteMap(default: $route);
$dynamicHandlers = new HandlerMap($registrations);
$dynamicListeners = new ListenerMap();
$beforeResolution = memory_get_usage(false);
foreach ($objects as $item) {
    if ($dynamicRoutes->for($item) !== $route || ($dynamicHandlers->for($item))($item) !== $item::class) {
        throw new RuntimeException('Distinct class mapping changed behavior.');
    }
    foreach ($dynamicListeners->getListenersForEvent($item) as $listener) {
        $listener($item);
    }
}
$afterResolution = memory_get_usage(false);
unset($objects, $registrations, $dynamicRoutes, $dynamicHandlers, $dynamicListeners);
gc_collect_cycles();
$afterDisposal = memory_get_usage(false);

fwrite(STDOUT, json_encode([
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'iterations' => $iterations,
    'resolved_class_count' => $count,
    'measurements' => $measurements,
    'dynamic_lookup' => [
         'map_resolution_growth_bytes' => $afterResolution - $beforeResolution,
        'remaining_after_map_disposal_bytes' => $afterDisposal - $beforeResolution,
        'note' => 'Uses distinct existing classes; maps can be released but PHP class definitions remain resident.',
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

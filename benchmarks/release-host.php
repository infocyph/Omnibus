<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class OmnibusReleaseHttpMessage
{
    public function __construct(public int $value) {}
}

$handlers = new HandlerMap([
    OmnibusReleaseHttpMessage::class => static function (OmnibusReleaseHttpMessage $message): int {
        if ($message->value < 0) {
            throw new RuntimeException('expected benchmark failure');
        }

        return $message->value;
    },
]);
$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker($handlers))]),
);

header('Content-Type: application/json');
$failurePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/failure';

try {
    $message = new OmnibusReleaseHttpMessage($failurePath ? -1 : 42);
    $bound = getenv('OMNIBUS_BENCHMARK_BOUND') === '1';
    $hostOnly = getenv('OMNIBUS_BENCHMARK_HOST_ONLY') === '1';
    if ($bound || $hostOnly) {
        $runtime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
            'omnibus-release-benchmark',
            workerSlot: 0,
            generation: 1,
        );
        $request = RequestContext::create($runtime);

        try {
            $envelope = $bound
                ? $bus->withRunwire($runtime, static fn() => $bus->dispatch($message), $request)
                : $bus->dispatch($message);
        } finally {
            $request->complete();
        }
    } else {
        $envelope = $bus->dispatch($message);
    }
    $handled = $envelope->last(HandledStamp::class);
    $value = $handled instanceof HandledStamp ? $handled->result : null;
    if ($failurePath || $value !== 42) {
        throw new RuntimeException('Unexpected benchmark result.');
    }

    http_response_code(200);
    file_put_contents('php://output', json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR));
} catch (Throwable $failure) {
    if (!$failurePath || $failure->getMessage() !== 'expected benchmark failure') {
        http_response_code(500);
        file_put_contents('php://output', json_encode(['ok' => false, 'error' => 'unexpected'], JSON_THROW_ON_ERROR));

        return;
    }

    http_response_code(503);
    file_put_contents('php://output', json_encode(['ok' => false, 'error' => 'expected'], JSON_THROW_ON_ERROR));
}

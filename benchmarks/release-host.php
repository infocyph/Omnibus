<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class OmnibusReleaseHttpMessage
{
    public function __construct(public int $value)
    {
    }
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
    $envelope = $bus->dispatch(new OmnibusReleaseHttpMessage($failurePath ? -1 : 42));
    $handled = $envelope->last(HandledStamp::class);
    $value = $handled instanceof HandledStamp ? $handled->result : null;
    if ($failurePath || $value !== 42) {
        throw new RuntimeException('Unexpected benchmark result.');
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);
} catch (Throwable $failure) {
    if (!$failurePath || $failure->getMessage() !== 'expected benchmark failure') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'unexpected'], JSON_THROW_ON_ERROR);

        return;
    }

    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'expected'], JSON_THROW_ON_ERROR);
}

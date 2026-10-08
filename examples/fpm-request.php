<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

require __DIR__ . '/vendor/autoload.php';

final readonly class ExampleFpmMessage
{
    public function __construct(public int $value) {}
}

$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker(new HandlerMap([
        ExampleFpmMessage::class => static fn(ExampleFpmMessage $message): int => $message->value,
    ])))]),
);
$result = $bus->dispatch(new ExampleFpmMessage(42))->last(HandledStamp::class)?->result;
http_response_code($result === 42 ? 200 : 500);
header('Content-Type: application/json');
echo json_encode(['ok' => $result === 42, 'value' => $result], JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

test('message bus dispatches synchronously through an explicit handler map', function (): void {
    $handlers = new HandlerMap([
        TestCommand::class => static fn(TestCommand $message): string => strtoupper($message->value),
    ]);
    $bus = new MessageBus(
        new RouteMap(),
        new TransportRegistry(['sync' => new SyncTransport($handlers)]),
    );

    $envelope = $bus->dispatch(new TestCommand('ready'));

    expect($envelope->last(HandledStamp::class)?->result)->toBe('READY')
        ->and($envelope->last(MessageIdStamp::class)?->id)->not->toBeEmpty();
});

test('route and handler maps resolve interface mappings', function (): void {
    $message = new class implements Stringable {
        public function __toString(): string
        {
            return 'mapped';
        }
    };
    $handlers = new HandlerMap([
        Stringable::class => static fn(Stringable $value): string => (string) $value,
    ]);
    $bus = new MessageBus(
        new RouteMap(),
        new TransportRegistry(['sync' => new SyncTransport($handlers)]),
    );

    expect($bus->dispatch($message)->last(HandledStamp::class)?->result)->toBe('mapped');
});

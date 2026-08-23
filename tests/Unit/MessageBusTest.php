<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\AmbiguousHandler;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\AmbiguousRoute;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\FirstMessageContract;
use Infocyph\Omnibus\Tests\Fixtures\SecondMessageContract;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Transport\UnsupportedDelay;

test('message bus dispatches synchronously through an explicit handler map', function (): void {
    $handlers = new HandlerMap([
        TestCommand::class => static fn(TestCommand $message): string => strtoupper($message->value),
    ]);
    $bus = new MessageBus(
        new RouteMap(),
        new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker($handlers))]),
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
        new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker($handlers))]),
    );

    expect($bus->dispatch($message)->last(HandledStamp::class)?->result)->toBe('mapped');
});

test('explicit envelope delay wins and sync transport refuses positive delay', function (): void {
    $sender = new RecordingSender();
    $bus = new MessageBus(
        new RouteMap([TestCommand::class => new Route('recording', 'work', 30)]),
        new TransportRegistry(['recording' => $sender]),
    );
    $sent = $bus->dispatch(new Envelope(
        new TestCommand('delayed'),
        [new DelayStamp(5)],
    ));

    expect($sent->last(DelayStamp::class)?->seconds)->toBe(5.0)
        ->and(fn() => (new SyncTransport(new HandlerInvoker(new HandlerMap([
            TestCommand::class => static fn(): null => null,
        ]))))->send($sent, 'work'))
        ->toThrow(UnsupportedDelay::class);
});

test('conflicting interface maps fail deterministically', function (): void {
    $message = new class implements FirstMessageContract, SecondMessageContract {};
    $handlers = new HandlerMap([
        FirstMessageContract::class => static fn(): string => 'first',
        SecondMessageContract::class => static fn(): string => 'second',
    ]);
    $routes = new RouteMap([
        FirstMessageContract::class => new Route('one'),
        SecondMessageContract::class => new Route('two'),
    ]);

    expect(fn() => $handlers->for($message))->toThrow(AmbiguousHandler::class)
        ->and(fn() => $routes->for($message))->toThrow(AmbiguousRoute::class);
});

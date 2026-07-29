<?php

declare(strict_types=1);

use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Event\QueuedListener;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\QueuedTestListener;
use Infocyph\Omnibus\Tests\Fixtures\TestEvent;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

test('event dispatcher invokes synchronous listeners in configured order', function (): void {
    $calls = [];
    $dispatcher = new EventDispatcher(new ListenerMap([
        TestEvent::class => [
            static function (TestEvent $event) use (&$calls): void {
                $calls[] = 'first:' . $event->value;
            },
            static function (TestEvent $event) use (&$calls): void {
                $calls[] = 'second:' . $event->value;
            },
        ],
    ]));

    $event = new TestEvent('created');

    expect($dispatcher->dispatch($event))->toBe($event)
        ->and($calls)->toBe(['first:created', 'second:created']);
});

test('queued listeners use the message bus without executing synchronously', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $bus = new MessageBus(
        new RouteMap([QueuedListener::class => new Route('memory', 'listeners')]),
        new TransportRegistry([
            'memory' => $transport,
            'sync' => new SyncTransport(new HandlerMap([])),
        ]),
    );
    $dispatcher = new EventDispatcher(
        new ListenerMap([TestEvent::class => [new QueuedTestListener()]]),
        $bus,
    );

    $dispatcher->dispatch(new TestEvent('queued'));

    expect($transport->size('listeners'))->toBe(1);
});

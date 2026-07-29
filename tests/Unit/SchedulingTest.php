<?php

declare(strict_types=1);

use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Scheduling\MessageFactoryMap;
use Infocyph\Omnibus\Scheduling\MessageFactoryNotFound;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\TransportRegistry;

test('scheduled dispatch resolves an explicit factory key through the normal bus', function (): void {
    $sender = new RecordingSender();
    $dispatcher = new ScheduledMessageDispatcher(
        new MessageFactoryMap([
            'reports.daily' => static fn(): TestCommand => new TestCommand('daily'),
        ]),
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('recording', 'scheduled')]),
            new TransportRegistry(['recording' => $sender]),
        ),
    );

    $dispatcher->dispatch('reports.daily');

    expect($sender->count(TestCommand::class))->toBe(1)
        ->and($sender->sent()[0]['queue'])->toBe('scheduled');
});

test('unknown scheduled message keys fail explicitly', function (): void {
    expect(fn() => (new MessageFactoryMap([]))->create('missing'))
        ->toThrow(MessageFactoryNotFound::class);
});

<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\TransportRegistry;

function omnibusSqliteConnection(): Connection
{
    return new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
}

test('DBLayer integration dispatches only after the outer transaction commits', function (): void {
    $connection = omnibusSqliteConnection();
    $sender = new RecordingSender();
    $dispatcher = new AfterCommitDispatcher(
        $connection,
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('recording')]),
            new TransportRegistry(['recording' => $sender]),
        ),
    );

    $connection->transaction(function () use ($dispatcher, $sender): void {
        $dispatcher->dispatch(new TestCommand('committed'));
        expect($sender->count())->toBe(0);
    });

    expect($sender->count(TestCommand::class))->toBe(1);
});

test('DBLayer integration discards dispatch registered by a rolled-back transaction', function (): void {
    $connection = omnibusSqliteConnection();
    $sender = new RecordingSender();
    $dispatcher = new AfterCommitDispatcher(
        $connection,
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('recording')]),
            new TransportRegistry(['recording' => $sender]),
        ),
    );

    try {
        $connection->transaction(function () use ($dispatcher): void {
            $dispatcher->dispatch(new TestCommand('rolled-back'));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($sender->count())->toBe(0);
});

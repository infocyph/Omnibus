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
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Sender;
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

test('DBLayer integration dispatches immediately outside transactions and promotes nested callbacks', function (): void {
    $connection = omnibusSqliteConnection();
    $sender = new RecordingSender();
    $dispatcher = new AfterCommitDispatcher(
        $connection,
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('recording')]),
            new TransportRegistry(['recording' => $sender]),
        ),
    );

    $dispatcher->dispatch(new TestCommand('immediate'));
    expect($sender->count())->toBe(1);

    $connection->transaction(function () use ($connection, $dispatcher, $sender): void {
        $connection->transaction(function () use ($dispatcher): void {
            $dispatcher->dispatch(new TestCommand('nested'));
        });
        expect($sender->count())->toBe(1);
    });

    expect($sender->count())->toBe(2);
});

test('DBLayer integration discards nested rollback callbacks and cannot roll back after-commit failure', function (): void {
    $connection = omnibusSqliteConnection();
    $recording = new RecordingSender();
    $recordingDispatcher = new AfterCommitDispatcher(
        $connection,
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('recording')]),
            new TransportRegistry(['recording' => $recording]),
        ),
    );
    $connection->transaction(function () use ($connection, $recordingDispatcher): void {
        try {
            $connection->transaction(function () use ($recordingDispatcher): void {
                $recordingDispatcher->dispatch(new TestCommand('discarded nested'));
                throw new RuntimeException('savepoint rollback');
            });
        } catch (RuntimeException) {
        }
    });
    expect($recording->count())->toBe(0);

    $connection->statement('CREATE TABLE committed_state (id INTEGER PRIMARY KEY)');
    $failing = new class implements Sender {
        public function send(Envelope $envelope, string $queue): Envelope
        {
            if (!$envelope->message instanceof TestCommand || $queue !== 'default') {
                throw new LogicException('Unexpected after-commit dispatch.');
            }

            throw new RuntimeException('after-commit callback failed');
        }
    };
    $failingDispatcher = new AfterCommitDispatcher(
        $connection,
        new MessageBus(
            new RouteMap([TestCommand::class => new Route('failing')]),
            new TransportRegistry(['failing' => $failing]),
        ),
    );

    expect(fn() => $connection->transaction(function (Connection $connection) use ($failingDispatcher): void {
        $connection->insert('INSERT INTO committed_state (id) VALUES (?)', [1]);
        $failingDispatcher->dispatch(new TestCommand('committed despite callback failure'));
    }))->toThrow(RuntimeException::class, 'after-commit callback failed')
        ->and($connection->scalar('SELECT COUNT(*) FROM committed_state'))->toBe(1);
});

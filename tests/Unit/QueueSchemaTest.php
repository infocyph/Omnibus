<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;

test('queue schema is complete for every supported database driver', function (string $driver): void {
    $statements = QueueSchema::statements($driver);

    expect($statements)->toHaveCount(7)
        ->and(implode("\n", $statements))->toContain('payload_kind')
        ->toContain('CHECK')
        ->toContain('REFERENCES');
})->with(['mysql', 'pgsql', 'sqlite']);

test('sqlite schema executes and enforces durable-state constraints', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $connection->statement('PRAGMA foreign_keys = ON');
    foreach (QueueSchema::statements('sqlite') as $statement) {
        $connection->statement($statement);
    }

    expect(fn() => $connection->insert(
        'INSERT INTO omnibus_failures (id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['id', 'work', 'raw', 'invalid', 0, 1, 1, RuntimeException::class, 'reason'],
    ))->toThrow(RuntimeException::class)
        ->and(fn() => $connection->insert(
            "INSERT INTO omnibus_workflow_items (workflow_id, item_id, item_index, queue_name, payload, item_status) VALUES ('missing', 'item', 0, 'work', '{}', 'pending')",
        ))->toThrow(RuntimeException::class);
});

test('schema identifiers reject SQL fragments and overlong generated indexes', function (): void {
    expect(fn() => QueueSchema::statements('sqlite', 'messages; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => QueueSchema::statements('oracle'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => QueueSchema::statements('pgsql', str_repeat('m', 60)))
        ->toThrow(InvalidArgumentException::class);
});

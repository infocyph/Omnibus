<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;

/** @return array<string, mixed>|null */
function omnibusServiceDatabase(string $driver): ?array
{
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        return null;
    }

    $database = getenv('IC_SERVICE_DATABASE');
    $username = getenv('IC_SERVICE_USERNAME');
    $password = getenv('IC_SERVICE_PASSWORD');
    if (!is_string($database) || $database === '' || !is_string($username) || $username === '') {
        return null;
    }

    return [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'port' => $driver === 'mysql' ? 3306 : 5432,
        'database' => $database,
        'username' => $username,
        'password' => is_string($password) ? $password : '',
        'options' => [PDO::ATTR_TIMEOUT => 3],
    ];
}

test('durable lifecycle runs on each configured service database', function (string $driver): void {
    $config = omnibusServiceDatabase($driver);
    if ($config === null) {
        test()->markTestSkipped(sprintf('%s service database is not configured.', $driver));

        return;
    }

    $connection = new Connection(ConnectionConfig::fromArray($config));
    $tables = [
        'queue' => 'omnibus_matrix_messages',
        'failures' => 'omnibus_matrix_failures',
        'workflows' => 'omnibus_matrix_workflows',
        'items' => 'omnibus_matrix_workflow_items',
    ];
    foreach (array_reverse($tables) as $table) {
        $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    }

    try {
        foreach (QueueSchema::statements(
            $driver,
            $tables['queue'],
            $tables['failures'],
            $tables['workflows'],
            $tables['items'],
        ) as $statement) {
            $connection->statement($statement);
        }

        $serializer = TestSerializer::make();
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $transport = new DBLayerTransport($connection, $serializer, $clock, $tables['queue']);
        $transport->send(new Envelope(new TestCommand($driver)), 'work');
        $reservation = [...$transport->receive('work')][0];
        $transport->acknowledge($reservation);

        $failures = new DBLayerFailureStore($connection, $serializer, $tables['failures']);
        $workflows = new DBLayerWorkflowStore(
            $connection,
            $serializer,
            $tables['workflows'],
            $tables['items'],
        );
        $workflows->createBatch(
            '01DRIVERMATRIX000000000000',
            [new Envelope(new TestCommand('workflow'))],
            'work',
        );
        $transition = $workflows->succeed('01DRIVERMATRIX000000000000', 0);

        expect($reservation->envelope()->message)->toEqual(new TestCommand($driver))
            ->and($transport->size('work'))->toBe(0)
            ->and($failures->all())->toBe([])
            ->and($transition->state->succeeded)->toBe(1);
    } finally {
        foreach (array_reverse($tables) as $table) {
            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
})->with(['mysql', 'pgsql']);

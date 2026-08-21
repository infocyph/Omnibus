<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowTransport;

/** @return array<string, mixed>|null */
function omnibusServiceDatabase(string $driver): ?array
{
    $pdoDriver = match ($driver) {
        'mysql', 'mariadb' => 'mysql',
        'pgsql' => 'pgsql',
        'mssql' => 'sqlsrv',
        default => $driver,
    };
    if (!in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
        return null;
    }

    $database = getenv('IC_SERVICE_DATABASE');
    $serviceUsername = getenv('IC_SERVICE_USERNAME');
    $servicePassword = getenv('IC_SERVICE_PASSWORD');
    if (!is_string($database) || $database === '') {
        return null;
    }

    $username = $driver === 'mssql' ? getenv('IC_MSSQL_USER') : $serviceUsername;
    $password = $driver === 'mssql' ? getenv('IC_MSSQL_PASSWORD') : $servicePassword;
    if (!is_string($username) || $username === '') {
        return null;
    }

    $config = [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'port' => match ($driver) {
            'mysql' => 3306,
            'mariadb' => 3308,
            'pgsql' => 5432,
            'mssql' => 1433,
            default => 0,
        },
        'database' => $database,
        'username' => $username,
        'password' => is_string($password) ? $password : '',
    ];
    if ($driver === 'mssql') {
        $config['encrypt'] = true;
        $config['trust_server_certificate'] = true;
    }

    return $config;
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
        $claim = $workflows->claimPending('01DRIVERMATRIX000000000000')[0];
        $workflows->confirmDispatched(
            '01DRIVERMATRIX000000000000',
            $claim->item->itemId,
            $claim->token,
        );
        $workflows->markHandled(
            '01DRIVERMATRIX000000000000',
            $claim->item->itemId,
            $claim->item->index,
        );
        $transition = $workflows->succeed(
            '01DRIVERMATRIX000000000000',
            $claim->item->itemId,
            0,
        );

        expect($reservation->envelope()->message)->toEqual(new TestCommand($driver))
            ->and($transport->size('work'))->toBe(0)
            ->and($failures->all())->toBe([])
            ->and($transition->state->succeeded)->toBe(1);
    } finally {
        foreach (array_reverse($tables) as $table) {
            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
})->with(['mysql', 'mariadb', 'pgsql', 'mssql']);

test('mutation decisions remain writer-affine with a deliberately lagging replica', function (string $driver): void {
    $config = omnibusServiceDatabase($driver);
    $replicaDatabase = getenv('IC_SERVICE_REPLICA_DATABASE');
    if ($config === null || !is_string($replicaDatabase) || $replicaDatabase === '') {
        test()->markTestSkipped(sprintf('%s lagging replica is not configured.', $driver));

        return;
    }

    $config['read'] = [[
        'host' => $config['host'],
        'port' => $config['port'],
        'database' => $replicaDatabase,
        'username' => $config['username'],
        'password' => $config['password'],
    ]];
    $connection = new Connection(ConnectionConfig::fromArray($config));
    $tables = [
        'queue' => 'omnibus_affinity_messages',
        'failures' => 'omnibus_affinity_failures',
        'workflows' => 'omnibus_affinity_workflows',
        'items' => 'omnibus_affinity_items',
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
        $replicaLagProved = false;
        try {
            $connection->select(sprintf('SELECT COUNT(*) FROM %s', $tables['queue']));
        } catch (Throwable) {
            $replicaLagProved = true;
        }
        if (!$replicaLagProved) {
            throw new RuntimeException('Replica must intentionally omit the affinity test tables.');
        }

        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $serializer = TestSerializer::make();
        $transport = new DBLayerTransport($connection, $serializer, $clock, $tables['queue']);
        $store = new DBLayerWorkflowStore(
            $connection,
            $serializer,
            $tables['workflows'],
            $tables['items'],
            $clock,
        );
        $failures = new DBLayerFailureStore($connection, $serializer, $tables['failures']);
        $coordinator = new WorkflowCoordinator($store, $transport);
        $workflowTransport = new WorkflowTransport($transport, $coordinator);
        $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
        $id = $coordinator->batch([new TestCommand('writer')], 'work');
        $reservation = [...$workflowTransport->receive('work')][0];
        $scope->run($reservation->envelope(), static fn(): null => null);
        $workflowTransport->acknowledge($reservation);
        $failures->add(FailedMessage::decoded(
            'writer-failure',
            'work',
            new Envelope(new TestCommand('failed')),
            1,
            $clock->now(),
            RuntimeException::class,
            'failure',
        ));

        [$state, $failure] = $connection->transaction(
            static fn(): array => [$store->find($id), $failures->find('writer-failure')],
        );
        expect($state?->status)->toBe(WorkflowStatus::Completed)
            ->and($failure)->not->toBeNull();
    } finally {
        foreach (array_reverse($tables) as $table) {
            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
})->with(['mysql', 'mariadb', 'pgsql']);

test('workflow claims and terminal transitions remain coherent across service connections', function (string $driver): void {
    $config = omnibusServiceDatabase($driver);
    if ($config === null) {
        test()->markTestSkipped(sprintf('%s service database is not configured.', $driver));

        return;
    }

    $first = new Connection(ConnectionConfig::fromArray($config));
    $second = new Connection(ConnectionConfig::fromArray($config));
    $tables = [
        'queue' => 'omnibus_concurrency_messages',
        'failures' => 'omnibus_concurrency_failures',
        'workflows' => 'omnibus_concurrency_workflows',
        'items' => 'omnibus_concurrency_items',
    ];
    foreach (array_reverse($tables) as $table) {
        $first->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    }

    try {
        foreach (QueueSchema::statements(
            $driver,
            $tables['queue'],
            $tables['failures'],
            $tables['workflows'],
            $tables['items'],
        ) as $statement) {
            $first->statement($statement);
        }
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $serializer = TestSerializer::make();
        $storeA = new DBLayerWorkflowStore(
            $first,
            $serializer,
            $tables['workflows'],
            $tables['items'],
            $clock,
        );
        $storeB = new DBLayerWorkflowStore(
            $second,
            $serializer,
            $tables['workflows'],
            $tables['items'],
            $clock,
        );
        $id = str_repeat('b', 26);
        $storeA->createBatch($id, [
            new Envelope(new TestCommand('one')),
            new Envelope(new TestCommand('two')),
        ], 'work');
        $claimA = $storeA->claimPending($id, 1)[0];
        $claimB = $storeB->claimPending($id, 1)[0];
        expect($claimB->item->itemId)->not->toBe($claimA->item->itemId);
        $storeA->confirmDispatched($id, $claimA->item->itemId, $claimA->token);
        $storeB->confirmDispatched($id, $claimB->item->itemId, $claimB->token);
        $storeA->markHandled($id, $claimA->item->itemId, $claimA->item->index);
        $storeB->markHandled($id, $claimB->item->itemId, $claimB->item->index);
        $storeA->succeed($id, $claimA->item->itemId, $claimA->item->index);
        $terminal = $storeB->succeed($id, $claimB->item->itemId, $claimB->item->index);
        $duplicate = $storeA->succeed($id, $claimA->item->itemId, $claimA->item->index);

        expect($terminal->state->status)->toBe(WorkflowStatus::Completed)
            ->and($terminal->state->succeeded)->toBe(2)
            ->and($duplicate->itemChanged)->toBeFalse();

        $chainId = str_repeat('c', 26);
        $storeA->createChain($chainId, [
            new Envelope(new TestCommand('first')),
            new Envelope(new TestCommand('blocked')),
        ], 'work');
        $chainClaim = $storeA->claimPending($chainId)[0];
        expect($storeB->claimPending($chainId))->toBe([]);
        $storeA->releaseDispatchClaim($chainId, $chainClaim->item->itemId, $chainClaim->token);

        $staleId = str_repeat('s', 26);
        $storeA->createBatch($staleId, [new Envelope(new TestCommand('stale'))], 'work');
        $stale = $storeA->claimPending($staleId, leaseSeconds: 1)[0];
        $clock->advance('+2 seconds');
        $current = $storeB->claimPending($staleId)[0];
        expect(fn() => $storeA->confirmDispatched($staleId, $stale->item->itemId, $stale->token))
            ->toThrow(LogicException::class)
            ->and(fn() => $storeA->releaseDispatchClaim($staleId, $stale->item->itemId, $stale->token))
            ->toThrow(LogicException::class);
        $storeB->confirmDispatched($staleId, $current->item->itemId, $current->token);
        expect($storeA->itemStatus($staleId, $current->item->itemId, 0))
            ->toBe(WorkflowItemStatus::Dispatched);
    } finally {
        foreach (array_reverse($tables) as $table) {
            $first->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
})->with(['mysql', 'mariadb', 'pgsql', 'mssql']);

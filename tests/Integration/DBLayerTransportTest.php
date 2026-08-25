<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureRetryClaimUnavailable;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryLockProvider;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;
use Infocyph\Omnibus\Telemetry\ObservedTransport;
use Infocyph\Omnibus\Telemetry\TelemetrySink;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
use Infocyph\Omnibus\Workflow\WorkflowInconsistentDelivery;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowNotFound;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowTransport;

/** @return array{Connection, DBLayerTransport, DBLayerFailureStore, FrozenClock, JsonEnvelopeSerializer} */
function omnibusDatabaseQueue(?int $maxParams = null): array
{
    $configuration = [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ];
    if ($maxParams !== null) {
        $configuration['security'] = ['max_params' => $maxParams];
    }
    $connection = new Connection(ConnectionConfig::fromArray($configuration));
    foreach (QueueSchema::statements('sqlite') as $statement) {
        $connection->statement($statement);
    }
    $serializer = new JsonEnvelopeSerializer(
        new MessageCodecRegistry([
            new CallbackMessageCodec(
                'test.command.v1',
                TestCommand::class,
                static fn(TestCommand $message): array => ['value' => $message->value],
                static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
            ),
        ]),
        new StampCodecRegistry(CoreStampCodecs::all()),
    );
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00.123456+00:00'));

    return [
        $connection,
        new DBLayerTransport($connection, $serializer, $clock),
        new DBLayerFailureStore($connection, $serializer, clock: $clock),
        $clock,
        $serializer,
    ];
}

/**
 * Run an operation while another process holds SQLite's write lock.
 *
 * @param array<string, mixed> $configuration
 * @param callable():mixed $operation
 */
function omnibusWithSqliteWriteLock(array $configuration, callable $operation, int $holdMicroseconds): mixed
{
    if (
        !function_exists('pcntl_fork')
        || !function_exists('pcntl_sigprocmask')
        || !function_exists('pcntl_waitpid')
        || !function_exists('posix_kill')
    ) {
        test()->markTestSkipped('SQLite retry integration requires ext-pcntl and ext-posix.');

        return null;
    }

    $ready = sys_get_temp_dir() . '/omnibus-sqlite-lock-' . bin2hex(random_bytes(8));
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the SQLite lock holder.');
    }
    if ($pid === 0) {
        $blocker = new Connection(ConnectionConfig::fromArray($configuration));
        try {
            $blocker->begin();
            $blocker->update(
                'UPDATE omnibus_messages SET created_at = created_at WHERE id = (SELECT id FROM omnibus_messages LIMIT 1)',
            );
            file_put_contents($ready, 'ready');
            usleep($holdMicroseconds);
            $blocker->rollBack();
            omnibusTerminateSqliteLockHolder(15);
        } catch (Throwable) {
            omnibusTerminateSqliteLockHolder(9);
        }
    }

    try {
        for ($attempt = 0; $attempt < 500 && !is_file($ready); $attempt++) {
            clearstatcache(true, $ready);
            usleep(2_000);
        }
        if (!is_file($ready)) {
            throw new RuntimeException('SQLite lock holder did not become ready.');
        }

        return $operation();
    } finally {
        pcntl_waitpid($pid, $status);
        if (is_file($ready)) {
            unlink($ready);
        }
        if (!pcntl_wifsignaled($status) || pcntl_wtermsig($status) !== 15) {
            throw new RuntimeException('SQLite lock holder failed.');
        }
    }
}

function omnibusTerminateSqliteLockHolder(int $signal): never
{
    pcntl_sigprocmask(SIG_UNBLOCK, [$signal]);
    $pid = getmypid();
    if (!is_int($pid) || !posix_kill($pid, $signal)) {
        throw new RuntimeException('Unable to terminate the SQLite lock holder.');
    }

    while (true) {
        usleep(10_000);
    }
}

test('DBLayer transport sends, batch reserves, releases, and conditionally acknowledges', function (): void {
    [, $transport, , $clock] = omnibusDatabaseQueue();
    $sent = $transport->send(new Envelope(new TestCommand('first')), 'work');
    $transport->send(
        new Envelope(new TestCommand('delayed'), [new DelayStamp(2)]),
        'work',
    );

    $messageId = $sent->last(MessageIdStamp::class);
    $reservations = [...$transport->receive('work', 10, 5)];

    expect($messageId)->toBeInstanceOf(MessageIdStamp::class)
        ->and($reservations)->toHaveCount(1)
        ->and($reservations[0]->attempt)->toBe(1)
        ->and($transport->size('work'))->toBe(0);

    $transport->release($reservations[0], 1);
    expect($transport->size('work'))->toBe(0);

    $clock->advance('+1 second');
    $redelivered = [...$transport->receive('work')][0];
    expect($redelivered->attempt)->toBe(2);
    $transport->acknowledge($redelivered);

    $clock->advance('+1 second');
    expect($transport->size('work'))->toBe(1);
});

test('DBLayer caps queue reservations and workflow claims to the effective bind budget', function (): void {
    [$connection, $transport, , , $serializer] = omnibusDatabaseQueue(16);
    $messages = [];
    for ($index = 0; $index < 20; $index++) {
        $messages[] = new Envelope(new TestCommand('bounded-' . $index));
        $transport->send($messages[$index], 'bounded');
    }

    $reservations = [...$transport->receive('bounded', 1_000)];
    $receiveLimit = $connection->safeBatchSize(
        parametersPerRow: 1,
        fixedBindings: 2,
        requested: 1_000,
    );

    $store = new DBLayerWorkflowStore($connection, $serializer);
    $workflowId = '01LOWBINDLIMIT000000000000';
    $store->createBatch($workflowId, $messages, 'bounded-workflow');
    $claims = $store->claimPending($workflowId, 1_000);
    $claimLimit = $connection->safeBatchSize(
        parametersPerRow: 1,
        fixedBindings: 3,
        requested: 1_000,
    );

    expect($reservations)->toHaveCount($receiveLimit)
        ->and(count($reservations) + 2)->toBeLessThanOrEqual($connection->effectiveMaxBindParameters())
        ->and($claims)->toHaveCount($claimLimit)
        ->and(count($claims) + 3)->toBeLessThanOrEqual($connection->effectiveMaxBindParameters());
});

test('DBLayer transport exposes poison payloads as terminal reservations', function (): void {
    [$connection, $transport] = omnibusDatabaseQueue();
    $connection->insert(
        'INSERT INTO omnibus_messages (id, message_id, queue_name, payload, available_at, attempts, reserved_until, receipt, created_at) VALUES (?, ?, ?, ?, ?, 0, NULL, NULL, ?)',
        ['01POISON000000000000000000', 'poison-message', 'work', '{broken', 0, 0],
    );

    $reservation = [...$transport->receive('work')][0];

    expect($reservation->decodingFailure()?->payload)->toBe('{broken');
    $transport->reject($reservation);
    expect($transport->size('work'))->toBe(0);
});

test('DBLayer transport reclaims expired reservations and rejects stale receipts', function (): void {
    [, $transport, , $clock] = omnibusDatabaseQueue();
    $transport->send(new Envelope(new TestCommand('crash')), 'work');
    $stale = [...$transport->receive('work', visibilitySeconds: 1)][0];

    $clock->advance('+2 seconds');
    $current = [...$transport->receive('work')][0];

    expect($current->attempt)->toBe(2)
        ->and(fn() => $transport->acknowledge($stale))
        ->toThrow(InvalidReservation::class);
    $transport->acknowledge($current);
});

test('DBLayer alone bounds transaction retries and can succeed on a later attempt', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'omnibus-retry-success-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate a temporary SQLite database.');
    }
    $configuration = [
        'driver' => 'sqlite',
        'database' => $database,
        'options' => [PDO::ATTR_TIMEOUT => 0],
    ];
    $serializer = TestSerializer::make();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

    try {
        $seed = new Connection(ConnectionConfig::fromArray($configuration));
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $seed->statement($statement);
        }
        (new DBLayerTransport($seed, $serializer, $clock))
            ->send(new Envelope(new TestCommand('retry-success')), 'work');
        $seed->disconnect();

        $stats = omnibusWithSqliteWriteLock(
            $configuration,
            function () use ($configuration, $serializer, $clock): array {
                $connection = new Connection(ConnectionConfig::fromArray($configuration));
                $transport = new DBLayerTransport($connection, $serializer, $clock);
                $reservations = [...$transport->receive('work')];

                expect($reservations)->toHaveCount(1);

                $stats = $connection->transactionStats();
                expect($connection->resetRuntimeStateForReuse())->toBeTrue();
                $connection->disconnect();

                return $stats;
            },
            180_000,
        );

        expect($stats['total'])->toBeGreaterThan(1)
            ->and($stats['total'])->toBeLessThanOrEqual(3)
            ->and($stats['committed'])->toBe(1);
    } finally {
        if (is_file($database)) {
            unlink($database);
        }
    }
});

test('DBLayer alone exhausts transaction retries without Omnibus amplification', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'omnibus-retry-exhausted-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate a temporary SQLite database.');
    }
    $configuration = [
        'driver' => 'sqlite',
        'database' => $database,
        'options' => [PDO::ATTR_TIMEOUT => 0],
    ];
    $serializer = TestSerializer::make();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

    try {
        $seed = new Connection(ConnectionConfig::fromArray($configuration));
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $seed->statement($statement);
        }
        (new DBLayerTransport($seed, $serializer, $clock))
            ->send(new Envelope(new TestCommand('retry-exhausted')), 'work');
        $seed->disconnect();

        $stats = omnibusWithSqliteWriteLock(
            $configuration,
            function () use ($configuration, $serializer, $clock): array {
                $connection = new Connection(ConnectionConfig::fromArray($configuration));
                $transport = new DBLayerTransport($connection, $serializer, $clock);

                expect(fn() => [...$transport->receive('work')])->toThrow(TransactionException::class);

                $stats = $connection->transactionStats();
                expect($connection->resetRuntimeStateForReuse())->toBeTrue();
                $connection->disconnect();

                return $stats;
            },
            450_000,
        );

        expect($stats['total'])->toBe(3)
            ->and($stats['committed'])->toBe(0)
            ->and($stats['rolled_back'])->toBe(3)
            ->and($stats['deadlocks'])->toBe(2);
    } finally {
        if (is_file($database)) {
            unlink($database);
        }
    }
});

test('receipt-guarded query retries settle a reservation only once', function (string $operation): void {
    $database = tempnam(sys_get_temp_dir(), 'omnibus-query-retry-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate a temporary SQLite database.');
    }
    $configuration = [
        'driver' => 'sqlite',
        'database' => $database,
        'options' => [PDO::ATTR_TIMEOUT => 0],
    ];
    $serializer = TestSerializer::make();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

    try {
        $seed = new Connection(ConnectionConfig::fromArray($configuration));
        foreach (QueueSchema::statements('sqlite') as $statement) {
            $seed->statement($statement);
        }
        $seedTransport = new DBLayerTransport($seed, $serializer, $clock);
        $seedTransport->send(new Envelope(new TestCommand($operation)), 'work');
        $reservation = [...$seedTransport->receive('work')][0];
        $seed->disconnect();

        omnibusWithSqliteWriteLock(
            $configuration,
            function () use ($configuration, $serializer, $clock, $reservation, $operation): void {
                $connection = new Connection(ConnectionConfig::fromArray($configuration));
                $transport = new DBLayerTransport($connection, $serializer, $clock);
                if ($operation === 'acknowledge') {
                    $transport->acknowledge($reservation);
                } else {
                    $transport->release($reservation);
                }

                expect(fn() => $operation === 'acknowledge'
                    ? $transport->acknowledge($reservation)
                    : $transport->release($reservation))
                    ->toThrow(InvalidReservation::class)
                    ->and($transport->size('work'))->toBe($operation === 'acknowledge' ? 0 : 1);
                expect($connection->resetRuntimeStateForReuse())->toBeTrue();
                $connection->disconnect();
            },
            180_000,
        );
    } finally {
        if (is_file($database)) {
            unlink($database);
        }
    }
})->with(['acknowledge', 'release']);

test('DBLayer failure store round trips decoded and raw failures and prunes by time', function (): void {
    [, , $failures, $clock] = omnibusDatabaseQueue();
    $failures->add(FailedMessage::decoded(
        'decoded-1',
        'work',
        new Envelope(new TestCommand('failed')),
        2,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));
    $failures->add(FailedMessage::undecodable(
        'raw-1',
        'work',
        '{broken',
        1,
        $clock->now()->modify('+1 second'),
        JsonException::class,
        'Syntax error',
    ));

    expect($failures->all())->toHaveCount(2)
        ->and($failures->find('decoded-1')?->envelope?->message)
        ->toEqual(new TestCommand('failed'))
        ->and($failures->find('raw-1')?->payload)->toBe('{broken')
        ->and($failures->prune($clock->now()->modify('+500 milliseconds')))->toBe(1)
        ->and($failures->find('decoded-1'))->toBeNull()
        ->and($failures->remove('raw-1'))->toBeTrue()
        ->and($failures->clear())->toBe(0);
});

test('DBLayer failure retry claims exclude concurrent retries and recover after expiry', function (): void {
    [, , $failures, $clock] = omnibusDatabaseQueue();
    $failures->add(FailedMessage::decoded(
        'retry-claim',
        'work',
        new Envelope(new TestCommand('retry')),
        1,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));

    $stale = $failures->claimRetry('retry-claim', 1);
    expect(fn() => $failures->claimRetry('retry-claim', 1))
        ->toThrow(FailureRetryClaimUnavailable::class);

    $clock->advance('+2 seconds');
    $current = $failures->claimRetry('retry-claim', 1);

    expect($failures->releaseRetry($stale))->toBeFalse()
        ->and($failures->markRetrySent($stale))->toBeFalse()
        ->and($failures->markRetrySent($current))->toBeTrue()
        ->and(fn() => $failures->claimRetry('retry-claim', 1))
        ->toThrow(FailureRetryClaimUnavailable::class)
        ->and($failures->removeRetried($current))->toBeTrue()
        ->and($failures->find('retry-claim'))->toBeNull();
});

test('DBLayer workflow store persists chain progress and batch cancellation', function (): void {
    [$connection, , , , $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $store->createChain('01CHAIN0000000000000000000', [
        new Envelope(new TestCommand('one')),
        new Envelope(new TestCommand('two')),
    ], 'work');

    $first = $store->claimPending('01CHAIN0000000000000000000', 100);
    expect($store->claimPending('01CHAIN0000000000000000000'))->toBe([]);
    expect(fn() => $store->confirmDispatched(
        '01CHAIN0000000000000000000',
        $first[0]->item->itemId,
        'stale-claim',
    ))->toThrow(LogicException::class);
    $store->confirmDispatched(
        '01CHAIN0000000000000000000',
        $first[0]->item->itemId,
        $first[0]->token,
    );
    $store->markHandled('01CHAIN0000000000000000000', $first[0]->item->itemId, 0);
    $state = $store->succeed('01CHAIN0000000000000000000', $first[0]->item->itemId, 0);
    $secondClaims = $store->claimPending('01CHAIN0000000000000000000');

    expect($state->state->succeeded)->toBe(1)
        ->and($secondClaims)->toHaveCount(1);

    $second = $secondClaims[0];
    $store->confirmDispatched(
        '01CHAIN0000000000000000000',
        $second->item->itemId,
        $second->token,
    );
    $store->markHandled('01CHAIN0000000000000000000', $second->item->itemId, 1);
    expect($store->succeed('01CHAIN0000000000000000000', $second->item->itemId, 1)->state->status)
        ->toBe(WorkflowStatus::Completed);

    $store->createBatch('01BATCH0000000000000000000', [
        new Envelope(new TestCommand('one')),
        new Envelope(new TestCommand('two')),
    ], 'work');
    $cancelled = $store->cancel('01BATCH0000000000000000000');
    expect($cancelled->state->status)->toBe(WorkflowStatus::Cancelled)
        ->and($cancelled->state->cancelled)->toBe(2)
        ->and($store->claimPending('01BATCH0000000000000000000'))->toBe([]);
});

test('DBLayer workflow domain failures escape without transaction replay', function (): void {
    [$connection, , , , $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $missingId = '01MISSINGWORKFLOW000000000';
    $beforeClaim = $connection->transactionStats()['total'] ?? 0;

    expect(fn() => $store->claimPending($missingId))->toThrow(WorkflowNotFound::class);
    $afterClaim = $connection->transactionStats()['total'];

    expect(fn() => $store->succeed($missingId, '01MISSINGITEM0000000000000', 0))
        ->toThrow(WorkflowNotFound::class);
    $afterTransition = $connection->transactionStats()['total'];

    expect($afterClaim - $beforeClaim)->toBe(1)
        ->and($afterTransition - $afterClaim)->toBe(1)
        ->and($connection->transactionLevel())->toBe(0);
});

test('same-connection DBLayer acknowledgement and workflow finalization are atomic', function (): void {
    [$connection, $transport, , , $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $coordinator = new WorkflowCoordinator($store, $transport);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $workflowTransport = new WorkflowTransport($transport, $coordinator);
    $id = $coordinator->batch([new TestCommand('atomic')], 'work');
    $reservation = [...$workflowTransport->receive('work')][0];

    $scope->run($reservation->envelope(), static fn(): null => null);
    $workflowTransport->acknowledge($reservation);

    expect($workflowTransport->size('work'))->toBe(0)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Completed)
        ->and($store->find($id)?->succeeded)->toBe(1);
});

test('DBLayer atomic settlement rolls back queue deletion unless the item is handled', function (): void {
    [$connection, $transport, , $clock, $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $coordinator = new WorkflowCoordinator($store, $transport);
    $id = $coordinator->batch([new TestCommand('not-handled')], 'work');
    $reservation = [...$transport->receive('work', visibilitySeconds: 1)][0];
    $transactionsBefore = $connection->transactionStats()['total'];

    expect(fn() => $transport->acknowledgeWorkflow($reservation, $store))
        ->toThrow(WorkflowInconsistentDelivery::class);
    $transactionsAfter = $connection->transactionStats()['total'];
    $clock->advance('+2 seconds');
    expect($transport->size('work'))->toBe(1)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Running)
        ->and($transactionsAfter - $transactionsBefore)->toBe(1);
});

test('a stale DBLayer workflow reservation cannot settle newer ownership', function (): void {
    [$connection, $transport, , $clock, $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $coordinator = new WorkflowCoordinator($store, $transport);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $id = $coordinator->batch([new TestCommand('stale')], 'work');
    $stale = [...$transport->receive('work', visibilitySeconds: 1)][0];
    $scope->run($stale->envelope(), static fn(): null => null);
    $clock->advance('+2 seconds');
    $current = [...$transport->receive('work')][0];

    expect(fn() => $transport->acknowledgeWorkflow($stale, $store))
        ->toThrow(InvalidReservation::class);
    $transport->acknowledgeWorkflow($current, $store);

    expect($store->find($id)?->status)->toBe(WorkflowStatus::Completed)
        ->and($transport->size('work'))->toBe(0);
});

test('transparent transport decorators preserve DBLayer atomic workflow settlement', function (string $decorators): void {
    [$connection, $transport, , $clock, $serializer] = omnibusDatabaseQueue();
    $sink = new class implements TelemetrySink {
        public function record(string $metric, float|int $value, array $attributes = []): void {}
    };
    $decorated = match ($decorators) {
        'unique' => new UniqueTransport($transport, new InMemoryLockProvider()),
        'observed' => new ObservedTransport($transport, $sink, $clock, 'database'),
        'combined' => new ObservedTransport(
            new UniqueTransport($transport, new InMemoryLockProvider()),
            $sink,
            $clock,
            'database',
        ),
    };
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $coordinator = new WorkflowCoordinator($store, $decorated);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $workflowTransport = new WorkflowTransport($decorated, $coordinator);
    $id = $coordinator->batch([new TestCommand($decorators)], 'work');
    $reservation = [...$workflowTransport->receive('work')][0];
    $scope->run($reservation->envelope(), static fn(): null => null);
    $workflowTransport->acknowledge($reservation);

    expect($workflowTransport->size('work'))->toBe(0)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Completed);
})->with(['unique', 'observed', 'combined']);

test('poison workflow payloads terminalize by durable message metadata without decoding stamps', function (string $kind): void {
    [$connection, $transport, $failures, $clock, $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $coordinator = new WorkflowCoordinator($store, $transport);
    $id = $kind === 'chain'
        ? $coordinator->chain([new TestCommand('poison'), new TestCommand('later')], 'work')
        : $coordinator->batch([new TestCommand('poison'), new TestCommand('other')], 'work');
    $row = $connection->select(
        'SELECT id, message_id FROM omnibus_messages WHERE queue_name = ? ORDER BY id ASC LIMIT 1',
        ['work'],
    )[0];
    $messageId = (string) $row['message_id'];
    $item = $store->findItemByMessageId($messageId)
        ?? throw new RuntimeException('Workflow item metadata was not persisted.');
    $connection->update('UPDATE omnibus_messages SET payload = ? WHERE id = ?', ['{broken', $row['id']]);
    $consumer = new Consumer(
        new WorkflowTransport($transport, $coordinator),
        new HandlerInvoker(new HandlerMap([])),
        new ExponentialRetryStrategy(),
        new WorkflowFailureStore($failures, $coordinator),
        $clock,
    );

    $result = $consumer->run('work');
    $coordinator->failMessage($messageId);

    expect($result->failed)->toBe(1)
        ->and($failures->find($messageId)?->payload)->toBe('{broken')
        ->and($store->itemStatus($id, $item->itemId, $item->index))->toBe(WorkflowItemStatus::Failed)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Failed);
})->with(['chain', 'batch']);

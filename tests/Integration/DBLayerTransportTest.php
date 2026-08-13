<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureRetryClaimUnavailable;
use Infocyph\Omnibus\Handler\HandlerMap;
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
use Infocyph\Omnibus\Telemetry\ObservedTransport;
use Infocyph\Omnibus\Telemetry\TelemetrySink;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
use Infocyph\Omnibus\Workflow\WorkflowInconsistentDelivery;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowTransport;

/** @return array{Connection, DBLayerTransport, DBLayerFailureStore, FrozenClock, JsonEnvelopeSerializer} */
function omnibusDatabaseQueue(): array
{
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
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

    expect(fn() => $transport->acknowledgeWorkflow($reservation, $store))
        ->toThrow(WorkflowInconsistentDelivery::class);
    $clock->advance('+2 seconds');
    expect($transport->size('work'))->toBe(1)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Running);
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
        new HandlerMap([]),
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

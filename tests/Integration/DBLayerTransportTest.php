<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Transport\InvalidReservation;

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
        new DBLayerFailureStore($connection, $serializer),
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
        'INSERT INTO omnibus_messages (id, queue_name, payload, available_at, attempts, reserved_until, receipt, created_at) VALUES (?, ?, ?, ?, 0, NULL, NULL, ?)',
        ['01POISON000000000000000000', 'work', '{broken', 0, 0],
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

test('DBLayer workflow store persists chain progress and batch cancellation', function (): void {
    [$connection, , , , $serializer] = omnibusDatabaseQueue();
    $store = new DBLayerWorkflowStore($connection, $serializer);
    $store->createChain('01CHAIN0000000000000000000', [
        new Envelope(new TestCommand('one')),
        new Envelope(new TestCommand('two')),
    ], 'work');

    $first = $store->pending('01CHAIN0000000000000000000', 100);
    $store->dispatched('01CHAIN0000000000000000000', $first[0]->itemId);
    $state = $store->succeed('01CHAIN0000000000000000000', 0);

    expect($state->succeeded)->toBe(1)
        ->and($store->pending('01CHAIN0000000000000000000'))->toHaveCount(1);

    $second = $store->pending('01CHAIN0000000000000000000')[0];
    $store->dispatched('01CHAIN0000000000000000000', $second->itemId);
    expect($store->succeed('01CHAIN0000000000000000000', 1)->status)
        ->toBe(WorkflowStatus::Completed);

    $store->createBatch('01BATCH0000000000000000000', [
        new Envelope(new TestCommand('one')),
        new Envelope(new TestCommand('two')),
    ], 'work');
    $cancelled = $store->cancel('01BATCH0000000000000000000');
    expect($cancelled->status)->toBe(WorkflowStatus::Cancelled)
        ->and($cancelled->cancelled)->toBe(2)
        ->and($store->pending('01BATCH0000000000000000000'))->toBe([]);
});

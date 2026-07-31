<?php

declare(strict_types=1);

use Infocyph\Omnibus\Broadcasting\Broadcast;
use Infocyph\Omnibus\Broadcasting\CallbackBroadcaster;
use Infocyph\Omnibus\Broadcasting\Channel;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\CancellationToken;
use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\ExecutionTimedOut;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\QueuedListener;
use Infocyph\Omnibus\Event\QueuedListenerHandler;
use Infocyph\Omnibus\Event\QueuedListenerNotConfigured;
use Infocyph\Omnibus\Event\QueuedListenerResolver;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\Broker\BrokerCapabilities;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;
use Infocyph\Omnibus\Integration\SQS\SqsTransport;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Serialization\CallbackEnvelopeSerializer;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryLockProvider;
use Infocyph\Omnibus\Tests\Fixtures\QueuedTestListener;
use Infocyph\Omnibus\Tests\Fixtures\RecordingBrokerBackend;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestEvent;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowTransport;

test('callback serializer enforces payload bounds in both directions', function (): void {
    $serializer = new CallbackEnvelopeSerializer(
        static fn(string $payload): Envelope => new Envelope(new TestCommand($payload)),
        static fn(Envelope $envelope): string => $envelope->message->value,
        maximumBytes: 8,
    );

    expect($serializer->encode(new Envelope(new TestCommand('encoded'))))->toBe('encoded')
        ->and($serializer->decode('decoded')->message)->toEqual(new TestCommand('decoded'))
        ->and(fn() => $serializer->encode(new Envelope(new TestCommand('too-large'))))
        ->toThrow(LengthException::class)
        ->and(fn() => $serializer->decode(''))
        ->toThrow(LengthException::class);
});

test('callback broadcaster and queued listener adapters preserve provider boundaries', function (): void {
    $broadcasts = [];
    $broadcaster = new CallbackBroadcaster(
        static function (Broadcast $broadcast) use (&$broadcasts): void {
            $broadcasts[] = $broadcast;
        },
    );
    $broadcast = new Broadcast('order.updated', [new Channel('orders.42')], ['paid' => true]);
    $broadcaster->broadcast($broadcast);

    $events = [];
    $resolver = new QueuedListenerResolver([
        QueuedTestListener::class => static function (TestEvent $event) use (&$events): string {
            $events[] = $event->value;

            return 'handled';
        },
    ]);
    $handler = new QueuedListenerHandler($resolver);

    expect($handler(new QueuedListener(QueuedTestListener::class, new TestEvent('queued'))))
        ->toBe('handled')
        ->and($events)->toBe(['queued'])
        ->and($broadcasts)->toBe([$broadcast])
        ->and(fn() => (new QueuedListenerResolver([]))->resolve(QueuedTestListener::class))
        ->toThrow(QueuedListenerNotConfigured::class);
});

test('consumer task runs one bounded lifecycle independently of a command package', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = [];
    $transport->send(new Envelope(new TestCommand('task')), 'work');
    $consumer = new Consumer(
        $transport,
        new HandlerMap([
            TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                $handled[] = $message->value;
            },
        ]),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
    );

    $result = (new ConsumerTask($consumer))->run(new ConsumeRequest('work', 10, 30));

    expect($result->received)->toBe(1)
        ->and($result->succeeded)->toBe(1)
        ->and($handled)->toBe(['task'])
        ->and($transport->size('work'))->toBe(0);
});

test('cancellation token changes state at its exact deadline', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $deadline = new DateTimeImmutable('2026-01-01T00:00:01+00:00');
    $token = new CancellationToken($clock, $deadline);

    expect($token->isCancellationRequested())->toBeFalse();
    $clock->advance('+1 second');

    expect($token->isCancellationRequested())->toBeTrue()
        ->and(fn() => $token->throwIfCancellationRequested())
        ->toThrow(ExecutionTimedOut::class);
});

test('SQS and detached CacheLayer adapters retain their generic contracts', function (): void {
    $backend = new RecordingBrokerBackend(new BrokerCapabilities(true, true, false, 10));
    $transport = new SqsTransport($backend, TestSerializer::make());
    $transport->send(new Envelope(new TestCommand('sqs')), 'work');

    $locks = new DetachedLeaseAdapter(new InMemoryLockProvider());
    $lease = $locks->acquire('policy:key', 0, 10);

    expect($backend->sent)->toHaveCount(1)
        ->and($backend->sent[0]['queue'])->toBe('work')
        ->and($lease)->not->toBeNull()
        ->and($locks->refresh($lease, 20))->toBeTrue();

    $locks->release($lease);

    expect($locks->acquire('policy:key', 0, 10))->not->toBeNull();
});

test('workflow transport and failure store update durable workflow state', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $store = new InMemoryWorkflowStore();
    $coordinator = new WorkflowCoordinator($store, $inner);
    $transport = new WorkflowTransport($inner, $coordinator);
    $chainId = $coordinator->chain([new TestCommand('chain')], 'work');
    $reservation = [...$transport->receive('work')][0];

    $transport->acknowledge($reservation);

    expect($store->find($chainId)?->status)->toBe(WorkflowStatus::Completed)
        ->and($inner->size('work'))->toBe(0);

    $batchId = $coordinator->batch([new TestCommand('batch')], 'work');
    $batchReservation = [...$inner->receive('work')][0];
    $failures = new InMemoryFailureStore();
    $workflowFailures = new WorkflowFailureStore($failures, $coordinator);
    $workflowFailures->add(FailedMessage::decoded(
        'batch-failure',
        'work',
        $batchReservation->envelope(),
        1,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));

    expect($store->find($batchId)?->status)->toBe(WorkflowStatus::Failed)
        ->and($failures->find('batch-failure'))->not->toBeNull();
});

test('system clock returns the current instant', function (): void {
    $before = new DateTimeImmutable();
    $now = (new SystemClock())->now();
    $after = new DateTimeImmutable();

    expect($now >= $before)->toBeTrue()
        ->and($now <= $after)->toBeTrue();
});

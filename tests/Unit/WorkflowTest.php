<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\FailingClaimReleaseWorkflowStore;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\Omnibus\Workflow\BatchCompleted;
use Infocyph\Omnibus\Workflow\BatchFailed;
use Infocyph\Omnibus\Workflow\BatchFinalized;
use Infocyph\Omnibus\Workflow\ChainCompleted;
use Infocyph\Omnibus\Workflow\ChainFailed;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowDispatchFailed;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
use Infocyph\Omnibus\Workflow\WorkflowInconsistentDelivery;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowPostSettlementFailure;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowTransport;

test('chain dispatches strictly after handled settlement and stops after failure', function (): void {
    $events = [];
    $dispatcher = new EventDispatcher(new ListenerMap([
        ChainCompleted::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
        ChainFailed::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $workflows = new WorkflowCoordinator($store, $sender, $dispatcher);
    $id = $workflows->chain([new TestCommand('first'), new TestCommand('second')], 'work');

    $first = $sender->sent()[0]['envelope'];
    $scope->run($first, static fn(): string => 'handled');
    expect($sender->count())->toBe(1);
    $workflows->succeed($first);

    $second = $sender->sent()[1]['envelope'];
    $scope->run($second, static fn(): string => 'handled');
    $workflows->succeed($second);
    $workflows->succeed($second);

    expect($store->find($id)?->status)->toBe(WorkflowStatus::Completed)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ChainCompleted::class);

    $failedId = $workflows->chain([new TestCommand('fails'), new TestCommand('never')], 'work');
    $failedEnvelope = $sender->sent()[2]['envelope'];
    $workflows->fail($failedEnvelope);
    $workflows->fail($failedEnvelope);

    expect($sender->count())->toBe(3)
        ->and($store->find($failedId)?->status)->toBe(WorkflowStatus::Failed)
        ->and($events[1])->toBeInstanceOf(ChainFailed::class);
});

test('batch emits aggregate failure and finalization exactly once', function (): void {
    $events = [];
    $dispatcher = new EventDispatcher(new ListenerMap([
        BatchFailed::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
        BatchFinalized::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $workflows = new WorkflowCoordinator($store, $sender, $dispatcher);
    $id = $workflows->batch([new TestCommand('one'), new TestCommand('two')], 'work');

    $scope->run($sender->sent()[0]['envelope'], static fn(): null => null);
    $workflows->succeed($sender->sent()[0]['envelope']);
    $workflows->fail($sender->sent()[1]['envelope']);
    $workflows->fail($sender->sent()[1]['envelope']);

    expect($store->find($id)?->succeeded)->toBe(1)
        ->and($store->find($id)?->failed)->toBe(1)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Failed)
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(BatchFailed::class)
        ->and($events[1])->toBeInstanceOf(BatchFinalized::class);
});

test('completed workflows never regress and lifecycle events emit once', function (): void {
    $events = [];
    $dispatcher = new EventDispatcher(new ListenerMap([
        BatchCompleted::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
        BatchFinalized::class => [static function (object $event) use (&$events): void {
            $events[] = $event;
        }],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $workflows = new WorkflowCoordinator($store, $sender, $dispatcher);
    $id = $workflows->batch([new TestCommand('only')], 'work');
    $envelope = $sender->sent()[0]['envelope'];

    $scope->run($envelope, static fn(): null => null);
    $workflows->succeed($envelope);
    $workflows->succeed($envelope);
    $workflows->fail($envelope);
    $cancelled = $workflows->cancel($id);

    expect($cancelled->status)->toBe(WorkflowStatus::Completed)
        ->and($cancelled->succeeded)->toBe(1)
        ->and($cancelled->failed)->toBe(0)
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(BatchCompleted::class)
        ->and($events[1])->toBeInstanceOf(BatchFinalized::class);
});

test('workflow execution is item-aware and handled redelivery skips business code', function (string $kind): void {
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $workflows = new WorkflowCoordinator($store, $sender);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $id = $kind === 'chain'
        ? $workflows->chain([new TestCommand('item')], 'work')
        : $workflows->batch([new TestCommand('item')], 'work');
    $envelope = $sender->sent()[0]['envelope'];
    $handled = 0;

    $scope->run($envelope, static function () use (&$handled): void {
        $handled++;
    });
    $scope->run($envelope, static function () use (&$handled): void {
        $handled++;
    });

    expect($handled)->toBe(1);
    $workflows->cancel($id);
    $scope->run($envelope, static function () use (&$handled): void {
        $handled++;
    });

    expect($handled)->toBe(1);
})->with(['chain', 'batch']);

test('dispatch claims reject stale ownership and recover after expiry', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryWorkflowStore($clock);
    $id = '01WORKFLOW0000000000000000';
    $store->createBatch($id, [new Envelope(new TestCommand('one'))], 'work');

    $first = $store->claimPending($id, leaseSeconds: 1.0)[0];
    expect($store->claimPending($id))->toBe([])
        ->and(fn() => $store->confirmDispatched($id, $first->item->itemId, 'stale'))
        ->toThrow(LogicException::class);

    $clock->advance('+2 seconds');
    $second = $store->claimPending($id)[0];
    expect($second->token)->not->toBe($first->token)
        ->and(fn() => $store->releaseDispatchClaim($id, $first->item->itemId, $first->token))
        ->toThrow(LogicException::class);

    $store->confirmDispatched($id, $second->item->itemId, $second->token);
    expect($store->itemStatus($id, $second->item->itemId, 0))->toBe(WorkflowItemStatus::Dispatched);
});

test('a second chain coordinator cannot claim past an active predecessor', function (): void {
    $store = new InMemoryWorkflowStore();
    $id = '01WORKFLOW0000000000000000';
    $store->createChain($id, [
        new Envelope(new TestCommand('first')),
        new Envelope(new TestCommand('second')),
    ], 'work');
    $first = $store->claimPending($id)[0];

    expect($store->claimPending($id))->toBe([]);
    $store->confirmDispatched($id, $first->item->itemId, $first->token);
    $store->markHandled($id, $first->item->itemId, 0);
    $store->succeed($id, $first->item->itemId, 0);

    expect($store->claimPending($id))->toHaveCount(1);
});

test('batches dispatch every bounded chunk and oversized iterables stop at 1001', function (): void {
    $sender = new RecordingSender();
    $coordinator = new WorkflowCoordinator(new InMemoryWorkflowStore(), $sender);
    $messages = static function (int $count): Generator {
        for ($index = 0; $index < $count; $index++) {
            yield new TestCommand((string) $index);
        }
    };

    $coordinator->batch($messages(101), 'work');
    expect($sender->count())->toBe(101);

    $coordinator->batch($messages(1_000), 'work');
    expect($sender->count())->toBe(1_101)
        ->and(fn() => $coordinator->batch($messages(1_001), 'work'))
        ->toThrow(LengthException::class);
});

test('workflow transition facts are explicit and idempotent', function (): void {
    $store = new InMemoryWorkflowStore();
    $id = '01WORKFLOW0000000000000000';
    $store->createBatch($id, [new Envelope(new TestCommand('one'))], 'work');
    $claim = $store->claimPending($id)[0];
    $store->confirmDispatched($id, $claim->item->itemId, $claim->token);
    $store->markHandled($id, $claim->item->itemId, 0);

    $first = $store->succeed($id, $claim->item->itemId, 0);
    $duplicate = $store->succeed($id, $claim->item->itemId, 0);

    expect($first->itemChanged)->toBeTrue()
        ->and($first->completedNow)->toBeTrue()
        ->and($first->finalizedNow)->toBeTrue()
        ->and($duplicate->itemChanged)->toBeFalse()
        ->and($duplicate->completedNow)->toBeFalse();
});

test('handled redelivery reconciles an acknowledgement failure without rerunning business code', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $flaky = new class($inner) implements Transport {
        private bool $failAcknowledgement = true;

        public function __construct(private readonly InMemoryTransport $inner) {}

        public function acknowledge(Reservation $reservation): void
        {
            if ($this->failAcknowledgement) {
                $this->failAcknowledgement = false;

                throw new RuntimeException('Acknowledgement unavailable.');
            }
            $this->inner->acknowledge($reservation);
        }

        public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60): iterable
        {
            return $this->inner->receive($queue, $limit, $visibilitySeconds);
        }

        public function reject(Reservation $reservation): void
        {
            $this->inner->reject($reservation);
        }

        public function release(Reservation $reservation, float $delaySeconds = 0): void
        {
            $this->inner->release($reservation, $delaySeconds);
        }

        public function send(Envelope $envelope, string $queue): Envelope
        {
            return $this->inner->send($envelope, $queue);
        }

        public function size(string $queue): int
        {
            return $this->inner->size($queue);
        }
    };
    $store = new InMemoryWorkflowStore($clock);
    $coordinator = new WorkflowCoordinator($store, $flaky);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $transport = new WorkflowTransport($flaky, $coordinator);
    $id = $coordinator->batch([new TestCommand('once')], 'work');
    $first = [...$transport->receive('work', visibilitySeconds: 1)][0];
    $executions = 0;

    $scope->run($first->envelope(), static function () use (&$executions): void {
        $executions++;
    });
    expect(fn() => $transport->acknowledge($first))->toThrow(RuntimeException::class);

    $clock->advance('+2 seconds');
    $redelivery = [...$transport->receive('work')][0];
    $scope->run($redelivery->envelope(), static function () use (&$executions): void {
        $executions++;
    });
    $transport->acknowledge($redelivery);

    expect($executions)->toBe(1)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Completed)
        ->and($transport->size('work'))->toBe(0);
});

test('workflow eligibility is checked before a missing handler is resolved', function (string $terminal): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $store = new InMemoryWorkflowStore($clock);
    $coordinator = new WorkflowCoordinator($store, $inner);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $transport = new WorkflowTransport($inner, $coordinator);
    $id = $coordinator->batch([new TestCommand('removed-handler')], 'work');
    $reservation = [...$transport->receive('work')][0];
    $scope->run($reservation->envelope(), static fn(): null => null);
    if ($terminal === 'succeeded') {
        $coordinator->succeed($reservation->envelope());
    }
    $transport->release($reservation);

    $result = (new Consumer(
        $transport,
        new HandlerMap([]),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
        $scope,
    ))->run('work');

    expect($result->succeeded)->toBe(1)
        ->and($transport->size('work'))->toBe(0)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Completed);
})->with(['handled', 'succeeded']);

test('a normally dispatched workflow still requires its registered handler', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $store = new InMemoryWorkflowStore($clock);
    $coordinator = new WorkflowCoordinator($store, $inner);
    $id = $coordinator->batch([new TestCommand('missing')], 'work');
    $failures = new InMemoryFailureStore();
    $result = (new Consumer(
        new WorkflowTransport($inner, $coordinator),
        new HandlerMap([]),
        new ExponentialRetryStrategy(),
        new WorkflowFailureStore($failures, $coordinator),
        $clock,
        new WorkflowExecutionScope(new DirectExecutionScope(), $store),
    ))->run('work');

    expect($result->failed)->toBe(1)
        ->and($failures->all())->toHaveCount(1)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Failed);
});

test('workflow settlement refuses a dispatched item without acknowledging it', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $store = new InMemoryWorkflowStore($clock);
    $coordinator = new WorkflowCoordinator($store, $inner);
    $transport = new WorkflowTransport($inner, $coordinator);
    $coordinator->batch([new TestCommand('not-handled')], 'work');
    $reservation = [...$transport->receive('work', visibilitySeconds: 1)][0];

    expect(fn() => $transport->acknowledge($reservation))
        ->toThrow(WorkflowInconsistentDelivery::class);
    $clock->advance('+2 seconds');
    expect($transport->size('work'))->toBe(1);
});

test('workflow creation removes stale stamps and preserves a stable message identity', function (string $kind): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryWorkflowStore($clock);
    $id = '01WORKFLOW0000000000000000';
    $existingId = new MessageIdStamp('application-message');
    $envelope = new Envelope(new TestCommand('clean'), [
        new ChainStamp(str_repeat('c', 26), str_repeat('i', 26), 4),
        new BatchStamp(str_repeat('b', 26), str_repeat('j', 26), 5),
        $existingId,
    ]);
    if ($kind === 'chain') {
        $store->createChain($id, [$envelope], 'work');
    } else {
        $store->createBatch($id, [$envelope], 'work');
    }

    $first = $store->claimPending($id, leaseSeconds: 1)[0];
    $messageId = $first->item->envelope->last(MessageIdStamp::class);
    expect($first->item->envelope->all(ChainStamp::class))->toHaveCount($kind === 'chain' ? 1 : 0)
        ->and($first->item->envelope->all(BatchStamp::class))->toHaveCount($kind === 'batch' ? 1 : 0)
        ->and($messageId)->toBe($existingId);

    $clock->advance('+2 seconds');
    $reclaimed = $store->claimPending($id)[0];
    expect($reclaimed->item->envelope->last(MessageIdStamp::class)?->id)->toBe('application-message');
})->with(['chain', 'batch']);

test('workflow operations require the exact item ID and index pair', function (): void {
    $store = new InMemoryWorkflowStore();
    $id = '01WORKFLOW0000000000000000';
    $store->createBatch($id, [
        new Envelope(new TestCommand('zero')),
        new Envelope(new TestCommand('one')),
    ], 'work');
    $claims = $store->claimPending($id, 2);

    expect(fn() => $store->itemStatus($id, $claims[1]->item->itemId, 0))
        ->toThrow(WorkflowInconsistentDelivery::class)
        ->and(fn() => $store->fail($id, $claims[0]->item->itemId, 1))
        ->toThrow(WorkflowInconsistentDelivery::class);
});

test('cancellation during business execution is terminal-aware and never reports a handler failure', function (): void {
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $coordinator = new WorkflowCoordinator($store, $sender);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $id = $coordinator->batch([new TestCommand('running')], 'work');
    $envelope = $sender->sent()[0]['envelope'];
    $executions = 0;

    $scope->run($envelope, static function () use ($coordinator, $id, &$executions): void {
        $executions++;
        $coordinator->cancel($id);
    });

    $stamp = $envelope->last(BatchStamp::class);
    expect($executions)->toBe(1)
        ->and($stamp)->toBeInstanceOf(BatchStamp::class)
        ->and($store->markHandled($id, $stamp->itemId, $stamp->index))->toBe(WorkflowItemStatus::Cancelled)
        ->and($store->find($id)?->status)->toBe(WorkflowStatus::Cancelled);
});

test('a stale confirmation cannot regress a failed workflow to running', function (): void {
    $store = new InMemoryWorkflowStore();
    $id = '01WORKFLOW0000000000000000';
    $store->createBatch($id, [
        new Envelope(new TestCommand('fails')),
        new Envelope(new TestCommand('claimed')),
    ], 'work');
    $claims = $store->claimPending($id, 2);
    $store->fail($id, $claims[0]->item->itemId, 0);
    $store->confirmDispatched($id, $claims[1]->item->itemId, $claims[1]->token);

    expect($store->find($id)?->status)->toBe(WorkflowStatus::Failed);
});

test('partial dispatch failures expose the durable workflow and release every unattempted claim', function (int $failureAt): void {
    $store = new InMemoryWorkflowStore();
    $sender = new class($failureAt) implements Sender {
        public int $attempts = 0;

        public function __construct(private readonly int $failureAt) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            if ($queue === '') {
                throw new InvalidArgumentException('Queue cannot be empty.');
            }
            $this->attempts++;
            if ($this->attempts === $this->failureAt) {
                throw new RuntimeException('send failed');
            }

            return $envelope;
        }
    };
    $coordinator = new WorkflowCoordinator($store, $sender);

    try {
        $coordinator->batch([
            new TestCommand('sent'),
            new TestCommand('failed'),
            new TestCommand('unattempted'),
        ], 'work');
        test()->fail('Expected the initial dispatch to fail.');
    } catch (WorkflowDispatchFailed $failure) {
        expect($failure->getPrevious()?->getMessage())->toBe('send failed')
            ->and($store->find($failure->workflowId))->not->toBeNull();

        $available = $store->claimPending($failure->workflowId, 10);
        expect($available)->toHaveCount(4 - $failureAt)
            ->and(array_map(static fn($claim): int => $claim->item->index, $available))
            ->toBe(range($failureAt - 1, 2));
    }
})->with([1, 2, 3]);

test('a failed initial chain dispatch retains its recoverable workflow ID', function (): void {
    $store = new InMemoryWorkflowStore();
    $sender = new class() implements Sender {
        public function send(Envelope $envelope, string $queue): Envelope
        {
            throw new RuntimeException(sprintf(
                'Chain message %s unavailable on %s.',
                $envelope->message::class,
                $queue,
            ));
        }
    };

    try {
        (new WorkflowCoordinator($store, $sender))->chain([new TestCommand('first')], 'work');
        test()->fail('Expected chain dispatch failure.');
    } catch (WorkflowDispatchFailed $failure) {
        expect($store->find($failure->workflowId))->not->toBeNull()
            ->and($store->claimPending($failure->workflowId))->toHaveCount(1);
    }
});

test('claim cleanup failure never replaces the primary sender exception', function (): void {
    $store = new FailingClaimReleaseWorkflowStore(new InMemoryWorkflowStore());
    $sender = new class() implements Sender {
        public function send(Envelope $envelope, string $queue): Envelope
        {
            throw new DomainException(sprintf(
                'Primary sender failure for %s on %s.',
                $envelope->message::class,
                $queue,
            ));
        }
    };

    expect(fn() => (new WorkflowCoordinator($store, $sender))->batch([
        new TestCommand('one'),
        new TestCommand('two'),
    ], 'work'))->toThrow(WorkflowDispatchFailed::class, 'Initial dispatch');

    try {
        (new WorkflowCoordinator($store, $sender))->batch([new TestCommand('again')], 'work');
    } catch (WorkflowDispatchFailed $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(DomainException::class)
            ->and($failure->getPrevious()?->getMessage())
            ->toBe(sprintf('Primary sender failure for %s on work.', TestCommand::class));
    }
});

test('a next-chain dispatch failure is reported after the current item remains durably settled', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $sender = new class($inner) implements Transport {
        private int $sends = 0;

        public function __construct(private readonly InMemoryTransport $inner) {}

        public function acknowledge(Reservation $reservation): void { $this->inner->acknowledge($reservation); }
        public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60): iterable { return $this->inner->receive($queue, $limit, $visibilitySeconds); }
        public function reject(Reservation $reservation): void { $this->inner->reject($reservation); }
        public function release(Reservation $reservation, float $delaySeconds = 0): void { $this->inner->release($reservation, $delaySeconds); }
        public function size(string $queue): int { return $this->inner->size($queue); }

        public function send(Envelope $envelope, string $queue): Envelope
        {
            $this->sends++;
            if ($this->sends === 2) {
                throw new RuntimeException('next unavailable');
            }

            return $this->inner->send($envelope, $queue);
        }
    };
    $store = new InMemoryWorkflowStore($clock);
    $coordinator = new WorkflowCoordinator($store, $sender);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $transport = new WorkflowTransport($sender, $coordinator);
    $id = $coordinator->chain([new TestCommand('first'), new TestCommand('next')], 'work');
    $reservation = [...$transport->receive('work')][0];
    $scope->run($reservation->envelope(), static fn(): null => null);

    try {
        $transport->acknowledge($reservation);
        test()->fail('Expected chain advancement to fail.');
    } catch (WorkflowPostSettlementFailure $failure) {
        expect($failure->workflowId)->toBe($id)
            ->and($failure->operation)->toBe('dispatch-next')
            ->and($transport->size('work'))->toBe(0)
            ->and($failure->state->succeeded)->toBe(1)
            ->and($store->claimPending($id))->toHaveCount(1);
    }
});

test('workflow lifecycle listener failures are best effort after durable transitions', function (): void {
    $dispatcher = new EventDispatcher(new ListenerMap([
        BatchCompleted::class => [static function (): void {
            throw new RuntimeException('listener failed');
        }],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $coordinator = new WorkflowCoordinator($store, $sender, $dispatcher);
    $scope = new WorkflowExecutionScope(new DirectExecutionScope(), $store);
    $id = $coordinator->batch([new TestCommand('event')], 'work');
    $envelope = $sender->sent()[0]['envelope'];

    $scope->run($envelope, static fn(): null => null);
    $coordinator->succeed($envelope);

    expect($store->find($id)?->status)->toBe(WorkflowStatus::Completed);
});

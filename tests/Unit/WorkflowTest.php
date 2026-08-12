<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\Omnibus\Workflow\BatchCompleted;
use Infocyph\Omnibus\Workflow\BatchFailed;
use Infocyph\Omnibus\Workflow\BatchFinalized;
use Infocyph\Omnibus\Workflow\ChainCompleted;
use Infocyph\Omnibus\Workflow\ChainFailed;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowExecutionScope;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
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
    expect($store->itemStatus($id, 0))->toBe(WorkflowItemStatus::Dispatched);
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
    $store->markHandled($id, 0, $claim->item->itemId);

    $first = $store->succeed($id, 0);
    $duplicate = $store->succeed($id, 0);

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

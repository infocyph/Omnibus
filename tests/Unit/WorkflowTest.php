<?php

declare(strict_types=1);

use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Workflow\BatchFailed;
use Infocyph\Omnibus\Workflow\BatchFinalized;
use Infocyph\Omnibus\Workflow\BatchCompleted;
use Infocyph\Omnibus\Workflow\BatchCancellationScope;
use Infocyph\Omnibus\Workflow\ChainCompleted;
use Infocyph\Omnibus\Workflow\ChainFailed;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCancelled;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;

test('chain dispatches strictly in order and stops after a terminal failure', function (): void {
    $events = [];
    $eventDispatcher = new EventDispatcher(new ListenerMap([
        ChainCompleted::class => [
            static function (ChainCompleted $event) use (&$events): void {
                $events[] = $event;
            },
        ],
        ChainFailed::class => [
            static function (ChainFailed $event) use (&$events): void {
                $events[] = $event;
            },
        ],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $workflows = new WorkflowCoordinator($store, $sender, $eventDispatcher);
    $id = $workflows->chain([
        new TestCommand('first'),
        new TestCommand('second'),
    ], 'work');

    expect($sender->count())->toBe(1);
    $workflows->succeed($sender->sent()[0]['envelope']);
    expect($sender->count())->toBe(2);
    $workflows->succeed($sender->sent()[1]['envelope']);
    $workflows->succeed($sender->sent()[1]['envelope']);

    expect($store->find($id)?->status)->toBe(WorkflowStatus::Completed)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ChainCompleted::class);

    $failedId = $workflows->chain([
        new TestCommand('fails'),
        new TestCommand('never'),
    ], 'work');
    $failedEnvelope = $sender->sent()[2]['envelope'];
    $workflows->fail($failedEnvelope);
    $workflows->fail($failedEnvelope);

    expect($sender->count())->toBe(3)
        ->and($store->find($failedId)?->status)->toBe(WorkflowStatus::Failed)
        ->and($events[1])->toBeInstanceOf(ChainFailed::class);
});

test('batch records progress and emits named failure and finalization events', function (): void {
    $events = [];
    $eventDispatcher = new EventDispatcher(new ListenerMap([
        BatchFailed::class => [
            static function (BatchFailed $event) use (&$events): void {
                $events[] = $event;
            },
        ],
        BatchFinalized::class => [
            static function (BatchFinalized $event) use (&$events): void {
                $events[] = $event;
            },
        ],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $workflows = new WorkflowCoordinator($store, $sender, $eventDispatcher);
    $id = $workflows->batch([
        new TestCommand('one'),
        new TestCommand('two'),
    ], 'work');

    $workflows->succeed($sender->sent()[0]['envelope']);
    $workflows->fail($sender->sent()[1]['envelope']);
    $workflows->fail($sender->sent()[1]['envelope']);
    $state = $store->find($id);

    expect($state?->succeeded)->toBe(1)
        ->and($state?->failed)->toBe(1)
        ->and($state?->status)->toBe(WorkflowStatus::Failed)
        ->and($events[0])->toBeInstanceOf(BatchFailed::class)
        ->and($events[1])->toBeInstanceOf(BatchFinalized::class);
});

test('completed workflows never regress and terminal events are emitted once', function (): void {
    $events = [];
    $dispatcher = new EventDispatcher(new ListenerMap([
        BatchCompleted::class => [
            static function (BatchCompleted $event) use (&$events): void {
                $events[] = $event;
            },
        ],
        BatchFinalized::class => [
            static function (BatchFinalized $event) use (&$events): void {
                $events[] = $event;
            },
        ],
    ]));
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $workflows = new WorkflowCoordinator($store, $sender, $dispatcher);
    $id = $workflows->batch([new TestCommand('only')], 'work');
    $envelope = $sender->sent()[0]['envelope'];

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

test('cancellation scope applies to chain and batch messages', function (string $kind): void {
    $sender = new RecordingSender();
    $store = new InMemoryWorkflowStore();
    $workflows = new WorkflowCoordinator($store, $sender);
    $id = $kind === 'chain'
        ? $workflows->chain([new TestCommand('cancelled')], 'work')
        : $workflows->batch([new TestCommand('cancelled')], 'work');
    $envelope = $sender->sent()[0]['envelope'];
    $workflows->cancel($id);
    $scope = new BatchCancellationScope(new DirectExecutionScope(), $store);

    expect(fn() => $scope->run($envelope, static fn(): null => null))
        ->toThrow(WorkflowCancelled::class);
})->with(['chain', 'batch']);

test('workflow stores expose idempotent transition metadata', function (): void {
    $store = new InMemoryWorkflowStore();
    $id = '01WORKFLOW0000000000000000';
    $store->createBatch($id, [new Envelope(new TestCommand('one'))], 'work');

    $first = $store->succeed($id, 0);
    $duplicate = $store->succeed($id, 0);

    expect($first->changed)->toBeTrue()
        ->and($first->state->status)->toBe(WorkflowStatus::Completed)
        ->and($duplicate->changed)->toBeFalse()
        ->and($duplicate->state->status)->toBe(WorkflowStatus::Completed);
});

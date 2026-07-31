<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\UID\ULID;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class WorkflowCoordinator
{
    public function __construct(
        private WorkflowStore $store,
        private Sender $sender,
        private ?EventDispatcherInterface $events = null,
    ) {}

    /** @param iterable<object|Envelope> $messages */
    public function batch(iterable $messages, string $queue = 'default'): string
    {
        $id = ULID::generateMonotonic();
        $this->store->createBatch($id, self::envelopes($messages), $queue);
        $this->dispatchPending($id);

        return $id;
    }

    public function cancel(string $id): WorkflowState
    {
        $transition = $this->store->cancel($id);
        if ($transition->changed) {
            $this->finalizeBatch($transition->state);
        }

        return $transition->state;
    }

    /** @param iterable<object|Envelope> $messages */
    public function chain(iterable $messages, string $queue = 'default'): string
    {
        $id = ULID::generateMonotonic();
        $this->store->createChain($id, self::envelopes($messages), $queue);
        $this->dispatchPending($id, 1);

        return $id;
    }

    public function dispatchPending(string $id, int $limit = 100): int
    {
        $dispatched = 0;
        foreach ($this->store->pending($id, $limit) as $item) {
            $this->sender->send($item->envelope, $item->queue);
            $this->store->dispatched($id, $item->itemId);
            $dispatched++;
        }

        return $dispatched;
    }

    public function fail(Envelope $envelope): void
    {
        $chain = $envelope->last(ChainStamp::class);
        if ($chain instanceof ChainStamp) {
            $transition = $this->store->fail($chain->workflowId, $chain->index);
            if ($transition->changed) {
                $this->events?->dispatch(new ChainFailed($transition->state, $chain->index));
            }

            return;
        }
        $batch = $envelope->last(BatchStamp::class);
        if ($batch instanceof BatchStamp) {
            $transition = $this->store->fail($batch->workflowId, $batch->index);
            if ($transition->changed) {
                $this->events?->dispatch(new BatchFailed($transition->state, $batch->index));
                $this->finalizeBatch($transition->state);
            }
        }
    }

    public function succeed(Envelope $envelope): void
    {
        $chain = $envelope->last(ChainStamp::class);
        if ($chain instanceof ChainStamp) {
            $transition = $this->store->succeed($chain->workflowId, $chain->index);
            if (!$transition->changed) {
                return;
            }
            if ($transition->state->status === WorkflowStatus::Completed) {
                $this->events?->dispatch(new ChainCompleted($transition->state));
            } else {
                $this->dispatchPending($chain->workflowId, 1);
            }

            return;
        }
        $batch = $envelope->last(BatchStamp::class);
        if ($batch instanceof BatchStamp) {
            $transition = $this->store->succeed($batch->workflowId, $batch->index);
            if (!$transition->changed) {
                return;
            }
            if ($transition->state->status === WorkflowStatus::Completed) {
                $this->events?->dispatch(new BatchCompleted($transition->state));
            }
            $this->finalizeBatch($transition->state);
        }
    }

    /**
     * @param iterable<object|Envelope> $messages
     * @return list<Envelope>
     */
    private static function envelopes(iterable $messages): array
    {
        $envelopes = [];
        foreach ($messages as $message) {
            $envelopes[] = Envelope::wrap($message);
        }
        if ($envelopes === []) {
            throw new \InvalidArgumentException('A workflow requires at least one message.');
        }

        return $envelopes;
    }

    private function finalizeBatch(WorkflowState $state): void
    {
        if (
            $state->kind === 'batch'
            && $state->succeeded + $state->failed + $state->cancelled === $state->total
        ) {
            $this->events?->dispatch(new BatchFinalized($state));
        }
    }
}

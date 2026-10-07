<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\UID\ULID;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class WorkflowCoordinator
{
    private const int DISPATCH_CHUNK = 100;

    private const int MAX_ITEMS = 1_000;

    public function __construct(
        private WorkflowStore $store,
        private Sender $sender,
        private ?EventDispatcherInterface $events = null,
        private float $dispatchLeaseSeconds = 30.0,
    ) {
        if (!is_finite($dispatchLeaseSeconds) || $dispatchLeaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Workflow dispatch lease must be positive.');
        }
    }

    /** @param iterable<object|Envelope> $messages */
    public function batch(iterable $messages, string $queue = 'default'): string
    {
        $id = ULID::generateMonotonic();
        $this->store->createBatch($id, self::envelopes($messages), $queue);

        try {
            do {
                $dispatched = $this->dispatchPending($id, self::DISPATCH_CHUNK);
            } while ($dispatched === self::DISPATCH_CHUNK);
        } catch (\Throwable $failure) {
            throw new WorkflowDispatchFailed($id, $failure);
        }

        return $id;
    }

    public function cancel(string $id): WorkflowState
    {
        $transition = $this->store->cancel($id);
        $this->emitTransition($transition);

        return $transition->state;
    }

    /** @param iterable<object|Envelope> $messages */
    public function chain(iterable $messages, string $queue = 'default'): string
    {
        $id = ULID::generateMonotonic();
        $this->store->createChain($id, self::envelopes($messages), $queue);

        try {
            $this->dispatchPending($id, 1);
        } catch (\Throwable $failure) {
            throw new WorkflowDispatchFailed($id, $failure);
        }

        return $id;
    }

    public function dispatchPending(string $id, int $limit = self::DISPATCH_CHUNK): int
    {
        $claims = $this->store->claimPending($id, $limit, $this->dispatchLeaseSeconds);
        $dispatched = 0;
        foreach ($claims as $position => $claim) {
            try {
                $this->sender->send($claim->item->envelope, $claim->item->queue);
            } catch (\Throwable $failure) {
                for ($index = $position, $count = count($claims); $index < $count; $index++) {
                    try {
                        $unattempted = $claims[$index];
                        $this->store->releaseDispatchClaim(
                            $id,
                            $unattempted->item->itemId,
                            $unattempted->token,
                        );
                    } catch (\Throwable) {
                    }
                }

                throw $failure;
            }
            $this->store->confirmDispatched($id, $claim->item->itemId, $claim->token);
            $dispatched++;
        }

        return $dispatched;
    }

    public function fail(Envelope $envelope): void
    {
        $identity = WorkflowItem::identity($envelope);
        if ($identity === null) {
            return;
        }

        $transition = $this->store->fail(
            $identity['workflow_id'],
            $identity['item_id'],
            $identity['index'],
        );
        if ($transition->itemChanged) {
            $this->emitFailureEvents($identity, $transition);
        }
    }

    public function failMessage(string $messageId): void
    {
        $item = $this->store->findItemByMessageId($messageId);
        if ($item === null) {
            return;
        }

        $identity = WorkflowItem::identity($item->envelope);
        if ($identity === null) {
            throw new WorkflowInconsistentDelivery('Stored workflow item has no workflow identity.');
        }

        $transition = $this->store->fail($item->workflowId, $item->itemId, $item->index);
        if ($transition->itemChanged) {
            $this->emitFailureEvents($identity, $transition);
        }
    }

    public function settle(Transport $transport, Reservation $reservation): void
    {
        $envelope = $reservation->envelope();
        $identity = WorkflowItem::identity($envelope);
        if ($identity === null) {
            $transport->acknowledge($reservation);

            return;
        }

        $workflowId = $identity['workflow_id'];
        $itemId = $identity['item_id'];
        $index = $identity['index'];
        $status = $this->store->itemStatus($workflowId, $itemId, $index);
        if (in_array($status, [
            WorkflowItemStatus::Pending,
            WorkflowItemStatus::Dispatching,
            WorkflowItemStatus::Dispatched,
        ], true)) {
            throw new WorkflowInconsistentDelivery(sprintf(
                'Workflow item "%s:%d" cannot settle while it is %s.',
                $workflowId,
                $index,
                $status->value,
            ));
        }
        if (in_array($status, [
            WorkflowItemStatus::Succeeded,
            WorkflowItemStatus::Failed,
            WorkflowItemStatus::Cancelled,
        ], true)) {
            $transport->acknowledge($reservation);

            return;
        }

        if (
            $transport instanceof AtomicWorkflowTransport
            && $transport->supportsWorkflowStore($this->store)
        ) {
            $transition = $transport->acknowledgeWorkflow($reservation, $this->store);
        } else {
            $transport->acknowledge($reservation);
            $transition = $this->store->succeed($workflowId, $itemId, $index);
        }

        try {
            $this->advance($identity, $transition);
        } catch (\Throwable $failure) {
            throw new WorkflowPostSettlementFailure(
                $workflowId,
                $transition->state,
                'dispatch-next',
                $failure,
            );
        }
    }

    public function succeed(Envelope $envelope): void
    {
        $identity = WorkflowItem::identity($envelope);
        if ($identity === null) {
            return;
        }

        $this->advance(
            $identity,
            $this->store->succeed(
                $identity['workflow_id'],
                $identity['item_id'],
                $identity['index'],
            ),
        );
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
            if (count($envelopes) > self::MAX_ITEMS) {
                throw new \LengthException('A workflow cannot contain more than 1000 messages.');
            }
        }
        if ($envelopes === []) {
            throw new \InvalidArgumentException('A workflow requires at least one message.');
        }

        return $envelopes;
    }

    /** @param array{kind:'batch'|'chain',workflow_id:string,item_id:string,index:int} $identity */
    private function advance(array $identity, WorkflowTransition $transition): void
    {
        if (!$transition->itemChanged) {
            return;
        }

        if ($identity['kind'] === 'chain') {
            if ($transition->completedNow) {
                $this->dispatchEvent(new ChainCompleted($transition->state));
            } else {
                $this->dispatchPending($identity['workflow_id'], 1);
            }

            return;
        }

        if ($transition->completedNow) {
            $this->dispatchEvent(new BatchCompleted($transition->state));
        }
        if ($transition->finalizedNow) {
            $this->dispatchEvent(new BatchFinalized($transition->state));
        }
    }

    private function dispatchEvent(object $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable) {
        }
    }

    /** @param array{kind:'batch'|'chain',workflow_id:string,item_id:string,index:int} $identity */
    private function emitFailureEvents(
        array $identity,
        WorkflowTransition $transition,
    ): void {
        if ($identity['kind'] === 'chain') {
            if ($transition->failedNow) {
                $this->dispatchEvent(new ChainFailed($transition->state, $identity['index']));
            }

            return;
        }

        if ($transition->failedNow) {
            $this->dispatchEvent(new BatchFailed($transition->state, $identity['index']));
        }
        if ($transition->finalizedNow) {
            $this->dispatchEvent(new BatchFinalized($transition->state));
        }
    }

    private function emitTransition(WorkflowTransition $transition): void
    {
        if ($transition->cancelledNow) {
            $this->dispatchEvent(new WorkflowCancelled($transition->state));
        }
        if ($transition->finalizedNow && $transition->state->kind === 'batch') {
            $this->dispatchEvent(new BatchFinalized($transition->state));
        }
    }
}

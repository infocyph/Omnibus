<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
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

        do {
            $dispatched = $this->dispatchPending($id, self::DISPATCH_CHUNK);
        } while ($dispatched === self::DISPATCH_CHUNK);

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
        $this->dispatchPending($id, 1);

        return $id;
    }

    public function dispatchPending(string $id, int $limit = self::DISPATCH_CHUNK): int
    {
        $claims = $this->store->claimPending($id, $limit, $this->dispatchLeaseSeconds);
        $dispatched = 0;
        foreach ($claims as $claim) {
            try {
                $this->sender->send($claim->item->envelope, $claim->item->queue);
            } catch (\Throwable $failure) {
                $this->store->releaseDispatchClaim(
                    $id,
                    $claim->item->itemId,
                    $claim->token,
                );

                throw $failure;
            }
            $this->store->confirmDispatched($id, $claim->item->itemId, $claim->token);
            $dispatched++;
        }

        return $dispatched;
    }

    public function fail(Envelope $envelope): void
    {
        $identity = self::identity($envelope);
        if ($identity === null) {
            return;
        }

        [$workflowId, $index] = $identity;
        $transition = $this->store->fail($workflowId, $index);
        if (!$transition->itemChanged) {
            return;
        }

        if ($envelope->last(ChainStamp::class) instanceof ChainStamp) {
            if ($transition->failedNow) {
                $this->events?->dispatch(new ChainFailed($transition->state, $index));
            }

            return;
        }

        if ($transition->failedNow) {
            $this->events?->dispatch(new BatchFailed($transition->state, $index));
        }
        if ($transition->finalizedNow) {
            $this->events?->dispatch(new BatchFinalized($transition->state));
        }
    }

    public function settle(Transport $transport, Reservation $reservation): void
    {
        $envelope = $reservation->envelope();
        $identity = self::identity($envelope);
        if ($identity === null) {
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
            $transition = $this->store->succeed($identity[0], $identity[1]);
        }

        $this->advance($envelope, $transition);
    }

    public function succeed(Envelope $envelope): void
    {
        $identity = self::identity($envelope);
        if ($identity === null) {
            return;
        }

        $this->advance($envelope, $this->store->succeed($identity[0], $identity[1]));
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

    /** @return array{string, int}|null */
    private static function identity(Envelope $envelope): ?array
    {
        $chain = $envelope->last(ChainStamp::class);
        if ($chain instanceof ChainStamp) {
            return [$chain->workflowId, $chain->index];
        }

        $batch = $envelope->last(BatchStamp::class);

        return $batch instanceof BatchStamp
            ? [$batch->workflowId, $batch->index]
            : null;
    }

    private function advance(Envelope $envelope, WorkflowTransition $transition): void
    {
        if (!$transition->itemChanged) {
            return;
        }

        $chain = $envelope->last(ChainStamp::class);
        if ($chain instanceof ChainStamp) {
            if ($transition->completedNow) {
                $this->events?->dispatch(new ChainCompleted($transition->state));
            } else {
                $this->dispatchPending($chain->workflowId, 1);
            }

            return;
        }

        if ($transition->completedNow) {
            $this->events?->dispatch(new BatchCompleted($transition->state));
        }
        if ($transition->finalizedNow) {
            $this->events?->dispatch(new BatchFinalized($transition->state));
        }
    }

    private function emitTransition(WorkflowTransition $transition): void
    {
        if ($transition->cancelledNow) {
            $this->events?->dispatch(new WorkflowCancelled($transition->state));
        }
        if ($transition->finalizedNow && $transition->state->kind === 'batch') {
            $this->events?->dispatch(new BatchFinalized($transition->state));
        }
    }
}

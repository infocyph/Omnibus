<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Workflow\WorkflowDispatchClaim;
use Infocyph\Omnibus\Workflow\WorkflowItem;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowState;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowTransition;

final readonly class FailingClaimReleaseWorkflowStore implements WorkflowStore
{
    public function __construct(private WorkflowStore $inner) {}

    public function cancel(string $id): WorkflowTransition
    {
        return $this->inner->cancel($id);
    }

    /** @return list<WorkflowDispatchClaim> */
    public function claimPending(string $id, int $limit = 100, float $leaseSeconds = 30.0): array
    {
        return $this->inner->claimPending($id, $limit, $leaseSeconds);
    }

    public function confirmDispatched(string $id, string $itemId, string $claimToken): void
    {
        $this->inner->confirmDispatched($id, $itemId, $claimToken);
    }

    /** @param list<Envelope> $envelopes */
    public function createBatch(string $id, array $envelopes, string $queue): void
    {
        $this->inner->createBatch($id, $envelopes, $queue);
    }

    /** @param list<Envelope> $envelopes */
    public function createChain(string $id, array $envelopes, string $queue): void
    {
        $this->inner->createChain($id, $envelopes, $queue);
    }

    public function fail(string $id, string $itemId, int $index): WorkflowTransition
    {
        return $this->inner->fail($id, $itemId, $index);
    }

    public function find(string $id): ?WorkflowState
    {
        return $this->inner->find($id);
    }

    public function findItemByMessageId(string $messageId): ?WorkflowItem
    {
        return $this->inner->findItemByMessageId($messageId);
    }

    public function itemStatus(string $id, string $itemId, int $index): WorkflowItemStatus
    {
        return $this->inner->itemStatus($id, $itemId, $index);
    }

    public function markHandled(string $id, string $itemId, int $index): WorkflowItemStatus
    {
        return $this->inner->markHandled($id, $itemId, $index);
    }

    public function releaseDispatchClaim(string $id, string $itemId, string $claimToken): void
    {
        throw new \LogicException(sprintf(
            'Cleanup unavailable for workflow item "%s:%s" with claim "%s".',
            $id,
            $itemId,
            $claimToken,
        ));
    }

    public function succeed(string $id, string $itemId, int $index): WorkflowTransition
    {
        return $this->inner->succeed($id, $itemId, $index);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;

interface WorkflowStore
{
    public function cancel(string $id): WorkflowTransition;

    /** @return list<WorkflowDispatchClaim> */
    public function claimPending(
        string $id,
        int $limit = 100,
        float $leaseSeconds = 30.0,
    ): array;

    public function confirmDispatched(string $id, string $itemId, string $claimToken): void;

    /** @param list<Envelope> $envelopes */
    public function createBatch(string $id, array $envelopes, string $queue): void;

    /** @param list<Envelope> $envelopes */
    public function createChain(string $id, array $envelopes, string $queue): void;

    public function fail(string $id, int $index): WorkflowTransition;

    public function find(string $id): ?WorkflowState;

    public function itemStatus(string $id, int $index, ?string $itemId = null): WorkflowItemStatus;

    public function markHandled(string $id, int $index, ?string $itemId = null): void;

    public function releaseDispatchClaim(string $id, string $itemId, string $claimToken): void;

    public function succeed(string $id, int $index): WorkflowTransition;
}

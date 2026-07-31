<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;

interface WorkflowStore
{
    public function cancel(string $id): WorkflowTransition;

    /** @param list<Envelope> $envelopes */
    public function createBatch(string $id, array $envelopes, string $queue): void;

    /** @param list<Envelope> $envelopes */
    public function createChain(string $id, array $envelopes, string $queue): void;

    public function dispatched(string $id, string $itemId): void;

    public function fail(string $id, int $index): WorkflowTransition;

    public function find(string $id): ?WorkflowState;

    /** @return list<WorkflowItem> */
    public function pending(string $id, int $limit = 100): array;

    public function succeed(string $id, int $index): WorkflowTransition;
}

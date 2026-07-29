<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\UID\ULID;

final class InMemoryWorkflowStore implements WorkflowStore
{
    /**
     * @var array<string, array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem, status: string}>
     * }>
     */
    private array $workflows = [];

    public function cancel(string $id): WorkflowState
    {
        $workflow = &$this->workflow($id);
        foreach ($workflow['items'] as &$entry) {
            if ($entry['status'] === 'pending') {
                $entry['status'] = 'cancelled';
            }
        }
        unset($entry);
        $workflow['status'] = WorkflowStatus::Cancelled;

        return $this->state($id, $workflow);
    }

    public function createBatch(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'batch', $envelopes, $queue);
    }

    public function createChain(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'chain', $envelopes, $queue);
    }

    public function dispatched(string $id, string $itemId): void
    {
        $workflow = &$this->workflow($id);
        foreach ($workflow['items'] as &$entry) {
            if ($entry['item']->itemId === $itemId && $entry['status'] === 'pending') {
                $entry['status'] = 'dispatched';
                $workflow['status'] = WorkflowStatus::Running;

                return;
            }
        }

        throw new \LogicException(sprintf('Workflow item "%s" is not pending.', $itemId));
    }

    public function fail(string $id, int $index): WorkflowState
    {
        $workflow = &$this->workflow($id);
        $entry = &$this->entry($workflow, $index);
        if (!in_array($entry['status'], ['failed', 'succeeded'], true)) {
            $entry['status'] = 'failed';
        }
        if ($workflow['kind'] === 'chain') {
            foreach ($workflow['items'] as &$candidate) {
                if ($candidate['item']->index > $index && $candidate['status'] === 'pending') {
                    $candidate['status'] = 'cancelled';
                }
            }
            unset($candidate);
        }
        $workflow['status'] = WorkflowStatus::Failed;

        return $this->state($id, $workflow);
    }

    public function find(string $id): ?WorkflowState
    {
        $workflow = $this->workflows[$id] ?? null;

        return $workflow === null ? null : $this->state($id, $workflow);
    }

    public function pending(string $id, int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Pending workflow limit must be positive.');
        }
        $workflow = $this->workflow($id);
        if (
            $workflow['status'] === WorkflowStatus::Cancelled
            || ($workflow['kind'] === 'chain' && $workflow['status'] === WorkflowStatus::Failed)
        ) {
            return [];
        }

        $items = [];
        foreach ($workflow['items'] as $entry) {
            if ($entry['status'] !== 'pending') {
                continue;
            }
            $items[] = $entry['item'];
            if ($workflow['kind'] === 'chain' || count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function succeed(string $id, int $index): WorkflowState
    {
        $workflow = &$this->workflow($id);
        $entry = &$this->entry($workflow, $index);
        if ($entry['status'] !== 'succeeded') {
            $entry['status'] = 'succeeded';
        }
        $state = $this->state($id, $workflow);
        if ($state->succeeded === $state->total) {
            $workflow['status'] = WorkflowStatus::Completed;
            $state = $this->state($id, $workflow);
        }

        return $state;
    }

    /**
     * @param array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem, status: string}>
     * } $workflow
     * @return array{item: WorkflowItem, status: string}
     */
    private function &entry(array &$workflow, int $index): array
    {
        if (!isset($workflow['items'][$index])) {
            throw new \OutOfBoundsException(sprintf('Workflow index %d does not exist.', $index));
        }

        return $workflow['items'][$index];
    }

    /**
     * @return array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem, status: string}>
     * }
     */
    private function &workflow(string $id): array
    {
        if (!isset($this->workflows[$id])) {
            throw new WorkflowNotFound(sprintf('Workflow "%s" was not found.', $id));
        }

        return $this->workflows[$id];
    }

    /**
     * @param 'batch'|'chain' $kind
     * @param list<Envelope> $envelopes
     */
    private function create(string $id, string $kind, array $envelopes, string $queue): void
    {
        if ($id === '' || $queue === '' || $envelopes === [] || isset($this->workflows[$id])) {
            throw new \InvalidArgumentException('Workflow ID, queue, and non-empty unique item list are required.');
        }
        $items = [];
        foreach ($envelopes as $index => $envelope) {
            $itemId = ULID::generateMonotonic();
            $stamp = $kind === 'chain'
                ? new ChainStamp($id, $index)
                : new BatchStamp($id, $itemId, $index);
            $items[] = [
                'item' => new WorkflowItem(
                    $id,
                    $itemId,
                    $index,
                    $queue,
                    $envelope->with($stamp),
                ),
                'status' => 'pending',
            ];
        }
        $this->workflows[$id] = [
            'kind' => $kind,
            'status' => WorkflowStatus::Pending,
            'items' => $items,
        ];
    }

    /**
     * @param array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem, status: string}>
     * } $workflow
     */
    private function state(string $id, array $workflow): WorkflowState
    {
        $succeeded = $failed = $cancelled = 0;
        foreach ($workflow['items'] as $entry) {
            $succeeded += $entry['status'] === 'succeeded' ? 1 : 0;
            $failed += $entry['status'] === 'failed' ? 1 : 0;
            $cancelled += $entry['status'] === 'cancelled' ? 1 : 0;
        }

        return new WorkflowState(
            $id,
            $workflow['kind'],
            $workflow['status'],
            count($workflow['items']),
            $succeeded,
            $failed,
            $cancelled,
        );
    }
}

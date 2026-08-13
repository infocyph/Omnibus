<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final class InMemoryWorkflowStore implements WorkflowStore
{
    /**
     * @var array<string, array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{
     *         item: WorkflowItem,
     *         status: WorkflowItemStatus,
     *         claim_token: string|null,
     *         claim_until: int|null
     *     }>
     * }>
     */
    private array $workflows = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock()) {}

    public function cancel(string $id): WorkflowTransition
    {
        $workflow = &$this->workflow($id);
        if (in_array($workflow['status'], [WorkflowStatus::Completed, WorkflowStatus::Cancelled], true)) {
            return new WorkflowTransition($this->state($id, $workflow));
        }

        $cancelled = 0;
        foreach ($workflow['items'] as &$entry) {
            if (!in_array($entry['status'], [
                WorkflowItemStatus::Pending,
                WorkflowItemStatus::Dispatching,
                WorkflowItemStatus::Dispatched,
            ], true)) {
                continue;
            }
            $entry['status'] = WorkflowItemStatus::Cancelled;
            $entry['claim_token'] = null;
            $entry['claim_until'] = null;
            $cancelled++;
        }
        unset($entry);

        $cancelledNow = $workflow['status'] !== WorkflowStatus::Failed;
        if ($cancelledNow) {
            $workflow['status'] = WorkflowStatus::Cancelled;
        }
        $state = $this->state($id, $workflow);

        return new WorkflowTransition(
            $state,
            itemChanged: $cancelled > 0,
            cancelledNow: $cancelledNow,
            finalizedNow: $cancelled > 0 && self::isFinalized($state),
        );
    }

    public function claimPending(
        string $id,
        int $limit = 100,
        float $leaseSeconds = 30.0,
    ): array {
        self::validateClaim($limit, $leaseSeconds);
        $workflow = &$this->workflow($id);
        if (
            $workflow['status'] === WorkflowStatus::Cancelled
            || ($workflow['kind'] === 'chain' && $workflow['status'] === WorkflowStatus::Failed)
        ) {
            return [];
        }

        $now = Time::fromDate($this->clock->now());
        $this->releaseExpiredClaims($id, $now);
        $expiresAt = Time::add($now, $leaseSeconds);

        return $workflow['kind'] === 'chain'
            ? $this->claimChainItem($id, $expiresAt)
            : $this->claimBatchItems($id, $limit, $expiresAt);
    }

    public function confirmDispatched(string $id, string $itemId, string $claimToken): void
    {
        $entry = &$this->entryByItem($this->workflow($id), $itemId);
        if (
            $entry['status'] !== WorkflowItemStatus::Dispatching
            || $entry['claim_token'] !== $claimToken
        ) {
            throw new \LogicException(sprintf('Workflow item "%s" has a stale dispatch claim.', $itemId));
        }

        $entry['status'] = WorkflowItemStatus::Dispatched;
        $entry['claim_token'] = null;
        $entry['claim_until'] = null;
        if ($this->workflows[$id]['status'] === WorkflowStatus::Pending) {
            $this->workflows[$id]['status'] = WorkflowStatus::Running;
        }
    }

    public function createBatch(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'batch', $envelopes, $queue);
    }

    public function createChain(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'chain', $envelopes, $queue);
    }

    public function fail(string $id, string $itemId, int $index): WorkflowTransition
    {
        $workflow = &$this->workflow($id);
        if (in_array($workflow['status'], [WorkflowStatus::Completed, WorkflowStatus::Cancelled], true)) {
            return new WorkflowTransition($this->state($id, $workflow));
        }

        $entry = &$this->entry($workflow, $index);
        self::assertIdentity($entry, $itemId);
        if (!in_array($entry['status'], [
            WorkflowItemStatus::Pending,
            WorkflowItemStatus::Dispatching,
            WorkflowItemStatus::Dispatched,
        ], true)) {
            return new WorkflowTransition($this->state($id, $workflow));
        }
        $entry['status'] = WorkflowItemStatus::Failed;
        $entry['claim_token'] = null;
        $entry['claim_until'] = null;
        $cancelled = 0;
        if ($workflow['kind'] === 'chain') {
            foreach ($workflow['items'] as &$candidate) {
                if (
                    $candidate['item']->index > $index
                    && in_array($candidate['status'], [
                        WorkflowItemStatus::Pending,
                        WorkflowItemStatus::Dispatching,
                        WorkflowItemStatus::Dispatched,
                    ], true)
                ) {
                    $candidate['status'] = WorkflowItemStatus::Cancelled;
                    $candidate['claim_token'] = null;
                    $candidate['claim_until'] = null;
                    $cancelled++;
                }
            }
            unset($candidate);
        }
        $failedNow = $workflow['status'] !== WorkflowStatus::Failed;
        $workflow['status'] = WorkflowStatus::Failed;
        $state = $this->state($id, $workflow);

        return new WorkflowTransition(
            $state,
            itemChanged: true,
            failedNow: $failedNow,
            finalizedNow: self::isFinalized($state),
        );
    }

    public function find(string $id): ?WorkflowState
    {
        $workflow = $this->workflows[$id] ?? null;

        return $workflow === null ? null : $this->state($id, $workflow);
    }

    public function findItemByMessageId(string $messageId): ?WorkflowItem
    {
        foreach ($this->workflows as $workflow) {
            foreach ($workflow['items'] as $entry) {
                $stamp = $entry['item']->envelope->last(MessageIdStamp::class);
                if ($stamp instanceof MessageIdStamp && $stamp->id === $messageId) {
                    return $entry['item'];
                }
            }
        }

        return null;
    }

    public function itemStatus(string $id, string $itemId, int $index): WorkflowItemStatus
    {
        $entry = $this->entry($this->workflow($id), $index);
        self::assertIdentity($entry, $itemId);

        return $entry['status'];
    }

    public function markHandled(string $id, string $itemId, int $index): WorkflowItemStatus
    {
        $workflow = &$this->workflow($id);
        $entry = &$this->entry($workflow, $index);
        self::assertIdentity($entry, $itemId);
        if ($entry['status'] === WorkflowItemStatus::Dispatched) {
            $entry['status'] = WorkflowItemStatus::Handled;

            return WorkflowItemStatus::Handled;
        }
        if (in_array($entry['status'], [
            WorkflowItemStatus::Handled,
            WorkflowItemStatus::Succeeded,
            WorkflowItemStatus::Failed,
            WorkflowItemStatus::Cancelled,
        ], true)) {
            return $entry['status'];
        }

        if (in_array($entry['status'], [WorkflowItemStatus::Pending, WorkflowItemStatus::Dispatching], true)) {
            throw new WorkflowInconsistentDelivery(sprintf(
                'Workflow item "%s:%d" cannot be marked handled while it is %s.',
                $id,
                $index,
                $entry['status']->value,
            ));
        }

        throw new \LogicException('Unhandled workflow item status.');
    }

    public function releaseDispatchClaim(string $id, string $itemId, string $claimToken): void
    {
        $entry = &$this->entryByItem($this->workflow($id), $itemId);
        if (
            $entry['status'] !== WorkflowItemStatus::Dispatching
            || $entry['claim_token'] !== $claimToken
        ) {
            throw new \LogicException(sprintf('Workflow item "%s" has a stale dispatch claim.', $itemId));
        }
        $entry['status'] = WorkflowItemStatus::Pending;
        $entry['claim_token'] = null;
        $entry['claim_until'] = null;
    }

    public function succeed(string $id, string $itemId, int $index): WorkflowTransition
    {
        $workflow = &$this->workflow($id);
        $entry = &$this->entry($workflow, $index);
        self::assertIdentity($entry, $itemId);
        if ($entry['status'] !== WorkflowItemStatus::Handled) {
            return new WorkflowTransition($this->state($id, $workflow));
        }

        $entry['status'] = WorkflowItemStatus::Succeeded;
        $state = $this->state($id, $workflow);
        $completedNow = $state->succeeded === $state->total
            && in_array($workflow['status'], [WorkflowStatus::Pending, WorkflowStatus::Running], true);
        if ($completedNow) {
            $workflow['status'] = WorkflowStatus::Completed;
            $state = $this->state($id, $workflow);
        }

        return new WorkflowTransition(
            $state,
            itemChanged: true,
            completedNow: $completedNow,
            finalizedNow: self::isFinalized($state),
        );
    }

    /** @param array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null} $entry */
    private static function assertIdentity(array $entry, string $itemId): void
    {
        if ($entry['item']->itemId !== $itemId) {
            throw new WorkflowInconsistentDelivery('Workflow item identity does not match its index.');
        }
    }

    private static function isFinalized(WorkflowState $state): bool
    {
        return $state->succeeded + $state->failed + $state->cancelled === $state->total;
    }

    private static function validateClaim(int $limit, float $leaseSeconds): void
    {
        if ($limit < 1 || $limit > 1_000 || !is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Workflow claim limit must be 1..1000 and its lease must be positive.',
            );
        }
    }

    /**
     * @param array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}>
     * } $workflow
     * @return array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}
     */
    private function &entry(array &$workflow, int $index): array
    {
        if (!isset($workflow['items'][$index])) {
            throw new \OutOfBoundsException(sprintf('Workflow index %d does not exist.', $index));
        }

        return $workflow['items'][$index];
    }

    /**
     * @param array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}>
     * } $workflow
     * @return array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}
     */
    private function &entryByItem(array &$workflow, string $itemId): array
    {
        foreach ($workflow['items'] as &$entry) {
            if ($entry['item']->itemId === $itemId) {
                return $entry;
            }
        }
        unset($entry);

        throw new \OutOfBoundsException(sprintf('Workflow item "%s" does not exist.', $itemId));
    }

    /**
     * @return array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}>
     * }
     */
    private function &workflow(string $id): array
    {
        if (!isset($this->workflows[$id])) {
            throw new WorkflowNotFound(sprintf('Workflow "%s" was not found.', $id));
        }

        return $this->workflows[$id];
    }

    /** @return list<WorkflowDispatchClaim> */
    private function claimBatchItems(string $id, int $limit, int $expiresAt): array
    {
        $workflow = &$this->workflow($id);
        $claims = [];
        foreach ($workflow['items'] as &$entry) {
            if ($entry['status'] !== WorkflowItemStatus::Pending) {
                continue;
            }

            $claims[] = $this->claimEntry($entry, $expiresAt);
            if (count($claims) >= $limit) {
                break;
            }
        }
        unset($entry);

        return $claims;
    }

    /** @return list<WorkflowDispatchClaim> */
    private function claimChainItem(string $id, int $expiresAt): array
    {
        $workflow = &$this->workflow($id);
        foreach ($workflow['items'] as &$entry) {
            if ($entry['status'] === WorkflowItemStatus::Succeeded) {
                continue;
            }
            if ($entry['status'] !== WorkflowItemStatus::Pending) {
                return [];
            }

            return [$this->claimEntry($entry, $expiresAt)];
        }

        return [];
    }

    /**
     * @param array{
     *     item: WorkflowItem,
     *     status: WorkflowItemStatus,
     *     claim_token: string|null,
     *     claim_until: int|null
     * } $entry
     */
    private function claimEntry(array &$entry, int $expiresAt): WorkflowDispatchClaim
    {
        $token = ULID::generateMonotonic();
        $entry['status'] = WorkflowItemStatus::Dispatching;
        $entry['claim_token'] = $token;
        $entry['claim_until'] = $expiresAt;

        return new WorkflowDispatchClaim($entry['item'], $token, $expiresAt);
    }

    /**
     * @param string $kind One of ``batch`` or ``chain``.
     * @param list<Envelope> $envelopes
     */
    private function create(string $id, string $kind, array $envelopes, string $queue): void
    {
        if ($kind !== 'batch' && $kind !== 'chain') {
            throw new \InvalidArgumentException('Workflow kind must be batch or chain.');
        }
        if (
            $id === ''
            || strlen($id) > 26
            || $envelopes === []
            || count($envelopes) > 1_000
            || isset($this->workflows[$id])
        ) {
            throw new \InvalidArgumentException('Workflows require a unique ID and between 1 and 1000 items.');
        }
        QueueName::assert($queue);
        $items = [];
        foreach ($envelopes as $index => $envelope) {
            $itemId = ULID::generateMonotonic();
            $envelope = $envelope->without(ChainStamp::class, BatchStamp::class);
            if (!$envelope->last(MessageIdStamp::class) instanceof MessageIdStamp) {
                $envelope = $envelope->with(new MessageIdStamp(ULID::generateMonotonic()));
            }
            $stamp = $kind === 'chain'
                ? new ChainStamp($id, $itemId, $index)
                : new BatchStamp($id, $itemId, $index);
            $items[] = [
                'item' => new WorkflowItem($id, $itemId, $index, $queue, $envelope->with($stamp)),
                'status' => WorkflowItemStatus::Pending,
                'claim_token' => null,
                'claim_until' => null,
            ];
        }
        $this->workflows[$id] = [
            'kind' => $kind,
            'status' => WorkflowStatus::Pending,
            'items' => $items,
        ];
    }

    private function releaseExpiredClaims(string $id, int $now): void
    {
        $workflow = &$this->workflow($id);
        foreach ($workflow['items'] as &$entry) {
            if (
                $entry['status'] === WorkflowItemStatus::Dispatching
                && $entry['claim_until'] !== null
                && $entry['claim_until'] <= $now
            ) {
                $entry['status'] = WorkflowItemStatus::Pending;
                $entry['claim_token'] = null;
                $entry['claim_until'] = null;
            }
        }
        unset($entry);
    }

    /**
     * @param array{
     *     kind: 'batch'|'chain',
     *     status: WorkflowStatus,
     *     items: list<array{item: WorkflowItem,status: WorkflowItemStatus,claim_token: string|null,claim_until: int|null}>
     * } $workflow
     */
    private function state(string $id, array $workflow): WorkflowState
    {
        $succeeded = $failed = $cancelled = 0;
        foreach ($workflow['items'] as $entry) {
            $succeeded += $entry['status'] === WorkflowItemStatus::Succeeded ? 1 : 0;
            $failed += $entry['status'] === WorkflowItemStatus::Failed ? 1 : 0;
            $cancelled += $entry['status'] === WorkflowItemStatus::Cancelled ? 1 : 0;
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

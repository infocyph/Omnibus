<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\Omnibus\Workflow\WorkflowDispatchClaim;
use Infocyph\Omnibus\Workflow\WorkflowInconsistentDelivery;
use Infocyph\Omnibus\Workflow\WorkflowItem;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowNotFound;
use Infocyph\Omnibus\Workflow\WorkflowState;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowTransition;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

/**
 * Durable workflow state. SQLite deployments must use one active workflow
 * coordinator/consumer writer because DBLayer 4.0 has no immediate-writer mode.
 */
final readonly class DBLayerWorkflowStore implements WorkflowStore
{
    private string $items;

    private string $workflows;

    public function __construct(
        private Connection $connection,
        private EnvelopeSerializer $serializer,
        string $workflowTable = 'omnibus_workflows',
        private string $itemTable = 'omnibus_workflow_items',
        private ClockInterface $clock = new SystemClock(),
    ) {
        $driver = $connection->getDriverName();
        $this->workflows = SqlIdentifier::quote($workflowTable, $driver);
        $this->items = SqlIdentifier::quote($this->itemTable, $driver);
    }

    public function cancel(string $id): WorkflowTransition
    {
        return $this->stateTransaction(function (Connection $connection) use ($id): WorkflowTransition {
            $before = $this->required($id, true);
            if (in_array($before->status, [WorkflowStatus::Completed, WorkflowStatus::Cancelled], true)) {
                return new WorkflowTransition($before);
            }

            $cancelled = $connection->update(
                "UPDATE {$this->items} SET item_status = 'cancelled', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_status IN ('pending', 'dispatching', 'dispatched')",
                [$id],
            );
            $cancelledNow = $before->status !== WorkflowStatus::Failed;
            $status = $cancelledNow ? 'cancelled' : 'failed';
            $connection->update(
                "UPDATE {$this->workflows} SET workflow_status = ?, cancelled = cancelled + ? WHERE id = ? AND workflow_status NOT IN ('completed', 'cancelled')",
                [$status, $cancelled, $id],
            );
            $state = $this->required($id);

            return new WorkflowTransition(
                $state,
                itemChanged: $cancelled > 0,
                cancelledNow: $cancelledNow,
                finalizedNow: $cancelled > 0 && self::isFinalized($state),
            );
        });
    }

    public function claimPending(
        string $id,
        int $limit = 100,
        float $leaseSeconds = 30.0,
    ): array {
        self::validateClaim($limit, $leaseSeconds);
        $claims = $this->connection->transaction(function (Connection $connection) use (
            $id,
            $limit,
            $leaseSeconds,
        ): array {
            $state = $this->required($id, true);
            if (
                $state->status === WorkflowStatus::Cancelled
                || ($state->kind === 'chain' && $state->status === WorkflowStatus::Failed)
            ) {
                return [];
            }

            $now = Time::fromDate($this->clock->now());
            $connection->update(
                "UPDATE {$this->items} SET item_status = 'pending', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_status = 'dispatching' AND dispatch_claim_until <= ?",
                [$id, $now],
            );
            $resolvedLimit = $state->kind === 'chain' ? 1 : $limit;
            $lock = match ($connection->getDriverName()) {
                'mysql', 'pgsql' => ' FOR UPDATE SKIP LOCKED',
                'sqlite' => '',
                default => throw new \LogicException('Unsupported DBLayer workflow driver.'),
            };
            $rows = $connection->select(
                "SELECT workflow_id, item_id, item_index, queue_name, payload FROM {$this->items} WHERE workflow_id = ? AND item_status = 'pending' ORDER BY item_index LIMIT {$resolvedLimit}{$lock}",
                [$id],
            );
            if ($rows === []) {
                return [];
            }

            $token = ULID::generateMonotonic();
            $expiresAt = Time::add($now, $leaseSeconds);
            $itemIds = [];
            foreach ($rows as $row) {
                $itemIds[] = self::string($row, 'item_id');
            }
            $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
            $changed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'dispatching', dispatch_claim_token = ?, dispatch_claim_until = ? WHERE workflow_id = ? AND item_status = 'pending' AND item_id IN ({$placeholders})",
                [$token, $expiresAt, $id, ...$itemIds],
            );
            if ($changed !== count($rows)) {
                throw new \RuntimeException('Workflow dispatch claim changed an unexpected number of items.');
            }

            $resolved = [];
            foreach ($rows as $row) {
                $resolved[] = new WorkflowDispatchClaim($this->hydrateItem($row), $token, $expiresAt);
            }

            return $resolved;
        });
        if (!is_array($claims) || !array_is_list($claims)) {
            throw new \LogicException('DBLayer returned invalid workflow claims.');
        }

        $resolved = [];
        foreach ($claims as $claim) {
            if (!$claim instanceof WorkflowDispatchClaim) {
                throw new \LogicException('DBLayer returned an invalid workflow claim.');
            }
            $resolved[] = $claim;
        }

        return $resolved;
    }

    public function confirmDispatched(string $id, string $itemId, string $claimToken): void
    {
        $this->connection->transaction(function (Connection $connection) use (
            $id,
            $itemId,
            $claimToken,
        ): void {
            $changed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'dispatched', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_id = ? AND item_status = 'dispatching' AND dispatch_claim_token = ?",
                [$id, $itemId, $claimToken],
            );
            if ($changed !== 1) {
                throw new \LogicException(sprintf(
                    'Workflow item "%s" has a stale dispatch claim.',
                    $itemId,
                ));
            }
            $connection->update(
                "UPDATE {$this->workflows} SET workflow_status = 'running' WHERE id = ? AND workflow_status = 'pending'",
                [$id],
            );
        });
    }

    public function createBatch(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'batch', $envelopes, $queue);
    }

    public function createChain(string $id, array $envelopes, string $queue): void
    {
        $this->create($id, 'chain', $envelopes, $queue);
    }

    public function fail(string $id, int $index): WorkflowTransition
    {
        return $this->stateTransaction(function (Connection $connection) use ($id, $index): WorkflowTransition {
            $before = $this->required($id, true);
            if (in_array($before->status, [WorkflowStatus::Completed, WorkflowStatus::Cancelled], true)) {
                return new WorkflowTransition($before);
            }

            $failed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'failed', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_index = ? AND item_status IN ('pending', 'dispatching', 'dispatched')",
                [$id, $index],
            );
            if ($failed !== 1) {
                return new WorkflowTransition($before);
            }
            $cancelled = 0;
            if ($before->kind === 'chain') {
                $cancelled = $connection->update(
                    "UPDATE {$this->items} SET item_status = 'cancelled', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_index > ? AND item_status IN ('pending', 'dispatching', 'dispatched')",
                    [$id, $index],
                );
            }
            $failedNow = $before->status !== WorkflowStatus::Failed;
            $connection->update(
                "UPDATE {$this->workflows} SET workflow_status = 'failed', failed = failed + 1, cancelled = cancelled + ? WHERE id = ? AND workflow_status NOT IN ('completed', 'cancelled')",
                [$cancelled, $id],
            );
            $state = $this->required($id);

            return new WorkflowTransition(
                $state,
                itemChanged: true,
                failedNow: $failedNow,
                finalizedNow: self::isFinalized($state),
            );
        });
    }

    public function find(string $id): ?WorkflowState
    {
        $rows = $this->connection->select(
            "SELECT id, kind, workflow_status, total, succeeded, failed, cancelled FROM {$this->workflows} WHERE id = ?",
            [$id],
        );

        return isset($rows[0]) ? $this->hydrateState($rows[0]) : null;
    }

    public function itemStatus(string $id, int $index, ?string $itemId = null): WorkflowItemStatus
    {
        $rows = $this->connection->select(
            "SELECT item_id, item_status FROM {$this->items} WHERE workflow_id = ? AND item_index = ?",
            [$id, $index],
        );
        if (!isset($rows[0])) {
            throw new WorkflowNotFound(sprintf('Workflow item "%s:%d" was not found.', $id, $index));
        }
        if ($itemId !== null && self::string($rows[0], 'item_id') !== $itemId) {
            throw new WorkflowInconsistentDelivery('Workflow item identity does not match its index.');
        }

        return self::status($rows[0]);
    }

    public function markHandled(string $id, int $index, ?string $itemId = null): void
    {
        $bindings = [$id, $index];
        $identity = '';
        if ($itemId !== null) {
            $identity = ' AND item_id = ?';
            $bindings[] = $itemId;
        }
        $changed = $this->connection->update(
            "UPDATE {$this->items} SET item_status = 'handled', handled_at = ? WHERE workflow_id = ? AND item_index = ?{$identity} AND item_status = 'dispatched'",
            [Time::fromDate($this->clock->now()), ...$bindings],
        );
        if ($changed === 1) {
            return;
        }
        if ($this->itemStatus($id, $index, $itemId) === WorkflowItemStatus::Handled) {
            return;
        }

        throw new WorkflowInconsistentDelivery(sprintf(
            'Workflow item "%s:%d" could not transition from dispatched to handled.',
            $id,
            $index,
        ));
    }

    public function releaseDispatchClaim(string $id, string $itemId, string $claimToken): void
    {
        $changed = $this->connection->update(
            "UPDATE {$this->items} SET item_status = 'pending', dispatch_claim_token = NULL, dispatch_claim_until = NULL WHERE workflow_id = ? AND item_id = ? AND item_status = 'dispatching' AND dispatch_claim_token = ?",
            [$id, $itemId, $claimToken],
        );
        if ($changed !== 1) {
            throw new \LogicException(sprintf(
                'Workflow item "%s" has a stale dispatch claim.',
                $itemId,
            ));
        }
    }

    public function succeed(string $id, int $index): WorkflowTransition
    {
        return $this->stateTransaction(function (Connection $connection) use ($id, $index): WorkflowTransition {
            $before = $this->required($id, true);
            $changed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'succeeded' WHERE workflow_id = ? AND item_index = ? AND item_status = 'handled'",
                [$id, $index],
            );
            if ($changed !== 1) {
                return new WorkflowTransition($before);
            }
            $connection->update(
                "UPDATE {$this->workflows} SET succeeded = succeeded + 1 WHERE id = ? AND succeeded + failed + cancelled < total",
                [$id],
            );
            $state = $this->required($id);
            $completedNow = $state->succeeded === $state->total
                && in_array($state->status, [WorkflowStatus::Pending, WorkflowStatus::Running], true);
            if ($completedNow) {
                $connection->update(
                    "UPDATE {$this->workflows} SET workflow_status = 'completed' WHERE id = ? AND workflow_status IN ('pending', 'running') AND succeeded = total",
                    [$id],
                );
                $state = $this->required($id);
            }

            return new WorkflowTransition(
                $state,
                itemChanged: true,
                completedNow: $completedNow,
                finalizedNow: self::isFinalized($state),
            );
        });
    }

    public function usesConnection(Connection $connection): bool
    {
        return $this->connection === $connection;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (
            (!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \UnexpectedValueException(sprintf('Workflow row "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    private static function isFinalized(WorkflowState $state): bool
    {
        return $state->succeeded + $state->failed + $state->cancelled === $state->total;
    }

    /** @param array<string, mixed> $row */
    private static function status(array $row): WorkflowItemStatus
    {
        return WorkflowItemStatus::tryFrom(self::string($row, 'item_status'))
            ?? throw new \UnexpectedValueException('Stored workflow item status is invalid.');
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Workflow row "%s" must be a string.', $key));
        }

        return $value;
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
     * @param string $kind One of ``batch`` or ``chain``.
     * @param list<Envelope> $envelopes
     */
    private function create(string $id, string $kind, array $envelopes, string $queue): void
    {
        if ($kind !== 'batch' && $kind !== 'chain') {
            throw new \InvalidArgumentException('Workflow kind must be batch or chain.');
        }
        if ($id === '' || strlen($id) > 26 || $envelopes === [] || count($envelopes) > 1_000) {
            throw new \InvalidArgumentException('Workflows require an ID and between 1 and 1000 messages.');
        }
        QueueName::assert($queue);
        $this->connection->transaction(function (Connection $connection) use (
            $id,
            $kind,
            $envelopes,
            $queue,
        ): void {
            $connection->insert(
                "INSERT INTO {$this->workflows} (id, kind, workflow_status, total, succeeded, failed, cancelled) VALUES (?, ?, 'pending', ?, 0, 0, 0)",
                [$id, $kind, count($envelopes)],
            );
            $rows = [];
            foreach ($envelopes as $index => $envelope) {
                $itemId = ULID::generateMonotonic();
                $stamp = $kind === 'chain'
                    ? new ChainStamp($id, $index)
                    : new BatchStamp($id, $itemId, $index);
                $rows[] = [
                    'workflow_id' => $id,
                    'item_id' => $itemId,
                    'item_index' => $index,
                    'queue_name' => $queue,
                    'payload' => $this->serializer->encode($envelope->with($stamp)),
                    'item_status' => 'pending',
                    'dispatch_claim_token' => null,
                    'dispatch_claim_until' => null,
                    'handled_at' => null,
                ];
            }
            $this->insertItems($connection, $rows);
        });
    }

    /** @param array<string, mixed> $row */
    private function hydrateItem(array $row): WorkflowItem
    {
        return new WorkflowItem(
            self::string($row, 'workflow_id'),
            self::string($row, 'item_id'),
            self::int($row, 'item_index'),
            self::string($row, 'queue_name'),
            $this->serializer->decode(self::string($row, 'payload')),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateState(array $row): WorkflowState
    {
        $status = WorkflowStatus::tryFrom(self::string($row, 'workflow_status'));
        if ($status === null) {
            throw new \UnexpectedValueException('Stored workflow status is invalid.');
        }

        return new WorkflowState(
            self::string($row, 'id'),
            self::string($row, 'kind'),
            $status,
            self::int($row, 'total'),
            self::int($row, 'succeeded'),
            self::int($row, 'failed'),
            self::int($row, 'cancelled'),
        );
    }

    /**
     * Use DBLayer's driver compiler for portable multi-row SQL, then its typed
     * raw insert path so PostgreSQL is not asked for an absent generated ID.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function insertItems(Connection $connection, array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            $builder = $connection->table($this->itemTable);
            $compiled = $connection->getCompiler()->compile($builder->toInsertPayload($chunk));
            if (!$connection->insert($compiled->sql, $compiled->bindings)) {
                throw new \RuntimeException('DBLayer did not insert workflow items.');
            }
        }
    }

    private function required(string $id, bool $forUpdate = false): WorkflowState
    {
        if (!$forUpdate) {
            return $this->find($id)
                ?? throw new WorkflowNotFound(sprintf('Workflow "%s" was not found.', $id));
        }

        $lock = match ($this->connection->getDriverName()) {
            'mysql', 'pgsql' => ' FOR UPDATE',
            'sqlite' => '',
            default => throw new \LogicException('Unsupported DBLayer workflow driver.'),
        };
        $rows = $this->connection->select(
            "SELECT id, kind, workflow_status, total, succeeded, failed, cancelled FROM {$this->workflows} WHERE id = ?{$lock}",
            [$id],
        );

        return isset($rows[0])
            ? $this->hydrateState($rows[0])
            : throw new WorkflowNotFound(sprintf('Workflow "%s" was not found.', $id));
    }

    /** @param callable(Connection):WorkflowTransition $operation */
    private function stateTransaction(callable $operation): WorkflowTransition
    {
        $transition = $this->connection->transaction($operation);
        if (!$transition instanceof WorkflowTransition) {
            throw new \LogicException('DBLayer returned an invalid workflow transaction result.');
        }

        return $transition;
    }
}

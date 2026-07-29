<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Workflow\WorkflowItem;
use Infocyph\Omnibus\Workflow\WorkflowNotFound;
use Infocyph\Omnibus\Workflow\WorkflowState;
use Infocyph\Omnibus\Workflow\WorkflowStatus;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Infocyph\UID\ULID;

final readonly class DBLayerWorkflowStore implements WorkflowStore
{
    private string $items;

    private string $workflows;

    public function __construct(
        private Connection $connection,
        private EnvelopeSerializer $serializer,
        string $workflowTable = 'omnibus_workflows',
        string $workflowItemTable = 'omnibus_workflow_items',
    ) {
        $driver = $connection->getDriverName();
        $this->workflows = SqlIdentifier::quote($workflowTable, $driver);
        $this->items = SqlIdentifier::quote($workflowItemTable, $driver);
    }

    public function cancel(string $id): WorkflowState
    {
        return $this->stateTransaction(function (Connection $connection) use ($id): WorkflowState {
            $cancelled = $connection->update(
                "UPDATE {$this->items} SET item_status = 'cancelled' WHERE workflow_id = ? AND item_status = 'pending'",
                [$id],
            );
            $connection->update(
                "UPDATE {$this->workflows} SET workflow_status = 'cancelled', cancelled = cancelled + ? WHERE id = ?",
                [$cancelled, $id],
            );

            return $this->required($id);
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

    public function dispatched(string $id, string $itemId): void
    {
        $changed = $this->connection->update(
            "UPDATE {$this->items} SET item_status = 'dispatched' WHERE workflow_id = ? AND item_id = ? AND item_status = 'pending'",
            [$id, $itemId],
        );
        if ($changed !== 1) {
            throw new \LogicException(sprintf('Workflow item "%s" is not pending.', $itemId));
        }
        $this->connection->update(
            "UPDATE {$this->workflows} SET workflow_status = 'running' WHERE id = ? AND workflow_status = 'pending'",
            [$id],
        );
    }

    public function fail(string $id, int $index): WorkflowState
    {
        return $this->stateTransaction(function (Connection $connection) use ($id, $index): WorkflowState {
            $state = $this->required($id);
            $failed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'failed' WHERE workflow_id = ? AND item_index = ? AND item_status NOT IN ('failed', 'succeeded', 'cancelled')",
                [$id, $index],
            );
            $cancelled = 0;
            if ($state->kind === 'chain') {
                $cancelled = $connection->update(
                    "UPDATE {$this->items} SET item_status = 'cancelled' WHERE workflow_id = ? AND item_index > ? AND item_status = 'pending'",
                    [$id, $index],
                );
            }
            $connection->update(
                "UPDATE {$this->workflows} SET workflow_status = 'failed', failed = failed + ?, cancelled = cancelled + ? WHERE id = ?",
                [$failed, $cancelled, $id],
            );

            return $this->required($id);
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

    public function pending(string $id, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Pending workflow limit must be between 1 and 1000.');
        }
        $state = $this->required($id);
        if (
            $state->status === WorkflowStatus::Cancelled
            || ($state->kind === 'chain' && $state->status === WorkflowStatus::Failed)
        ) {
            return [];
        }
        $resolvedLimit = $state->kind === 'chain' ? 1 : $limit;
        $rows = $this->connection->select(
            "SELECT workflow_id, item_id, item_index, queue_name, payload FROM {$this->items} WHERE workflow_id = ? AND item_status = 'pending' ORDER BY item_index LIMIT {$resolvedLimit}",
            [$id],
        );

        return array_values(array_map(fn(array $row): WorkflowItem => new WorkflowItem(
            self::string($row, 'workflow_id'),
            self::string($row, 'item_id'),
            self::int($row, 'item_index'),
            self::string($row, 'queue_name'),
            $this->serializer->decode(self::string($row, 'payload')),
        ), $rows));
    }

    public function succeed(string $id, int $index): WorkflowState
    {
        return $this->stateTransaction(function (Connection $connection) use ($id, $index): WorkflowState {
            $changed = $connection->update(
                "UPDATE {$this->items} SET item_status = 'succeeded' WHERE workflow_id = ? AND item_index = ? AND item_status IN ('pending', 'dispatched')",
                [$id, $index],
            );
            if ($changed === 1) {
                $connection->update(
                    "UPDATE {$this->workflows} SET succeeded = succeeded + 1 WHERE id = ?",
                    [$id],
                );
            }
            $state = $this->required($id);
            if ($state->succeeded === $state->total) {
                $connection->update(
                    "UPDATE {$this->workflows} SET workflow_status = 'completed' WHERE id = ?",
                    [$id],
                );
                $state = $this->required($id);
            }

            return $state;
        });
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Workflow row "%s" must be an integer.', $key));
        }

        return (int) $value;
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

    /**
     * @param 'batch'|'chain' $kind
     * @param list<Envelope> $envelopes
     */
    private function create(string $id, string $kind, array $envelopes, string $queue): void
    {
        if ($id === '' || $queue === '' || $envelopes === []) {
            throw new \InvalidArgumentException('Workflow ID, queue, and messages are required.');
        }
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
            foreach ($envelopes as $index => $envelope) {
                $itemId = ULID::generateMonotonic();
                $stamp = $kind === 'chain'
                    ? new ChainStamp($id, $index)
                    : new BatchStamp($id, $itemId, $index);
                $connection->insert(
                    "INSERT INTO {$this->items} (workflow_id, item_id, item_index, queue_name, payload, item_status) VALUES (?, ?, ?, ?, ?, 'pending')",
                    [
                        $id,
                        $itemId,
                        $index,
                        $queue,
                        $this->serializer->encode($envelope->with($stamp)),
                    ],
                );
            }
        });
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

    private function required(string $id): WorkflowState
    {
        return $this->find($id)
            ?? throw new WorkflowNotFound(sprintf('Workflow "%s" was not found.', $id));
    }

    /** @param callable(Connection):WorkflowState $operation */
    private function stateTransaction(callable $operation): WorkflowState
    {
        $state = $this->connection->transaction($operation);
        if (!$state instanceof WorkflowState) {
            throw new \LogicException('DBLayer returned an invalid workflow transaction result.');
        }

        return $state;
    }
}

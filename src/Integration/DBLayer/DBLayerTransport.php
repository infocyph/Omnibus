<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\Omnibus\Workflow\AtomicWorkflowTransport;
use Infocyph\Omnibus\Workflow\WorkflowInconsistentDelivery;
use Infocyph\Omnibus\Workflow\WorkflowItemStatus;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowTransition;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final readonly class DBLayerTransport implements AtomicWorkflowTransport, Transport
{
    private string $table;

    public function __construct(
        private Connection $connection,
        private EnvelopeSerializer $serializer,
        private ClockInterface $clock,
        string $table = 'omnibus_messages',
    ) {
        $this->table = SqlIdentifier::quote($table, $connection->getDriverName());
    }

    public function acknowledge(Reservation $reservation): void
    {
        $this->deleteReservation($reservation, $this->connection);
    }

    public function acknowledgeWorkflow(
        Reservation $reservation,
        WorkflowStore $store,
    ): WorkflowTransition {
        if (!$store instanceof DBLayerWorkflowStore || !$store->usesConnection($this->connection)) {
            throw new \LogicException('Atomic workflow settlement requires the same DBLayer connection.');
        }

        try {
            $transition = $this->connection->transaction(function (Connection $connection) use (
                $reservation,
                $store,
            ): WorkflowTransition {
                $envelope = $reservation->envelope();
                $chain = $envelope->last(ChainStamp::class);
                if ($chain instanceof ChainStamp) {
                    $identity = [$chain->workflowId, $chain->itemId, $chain->index];
                } else {
                    $batch = $envelope->last(BatchStamp::class);
                    if (!$batch instanceof BatchStamp) {
                        throw new \LogicException('Atomic workflow settlement requires a workflow stamp.');
                    }
                    $identity = [$batch->workflowId, $batch->itemId, $batch->index];
                }
                if ($store->itemStatusForUpdate(...$identity) !== WorkflowItemStatus::Handled) {
                    throw new WorkflowInconsistentDelivery(
                        'Atomic workflow settlement requires a handled item.',
                    );
                }
                $this->deleteReservation($reservation, $connection);

                return $store->succeed(...$identity);
            }, 3);
        } catch (TransactionException $failure) {
            $cause = $failure->getPrevious();
            if ($cause instanceof WorkflowInconsistentDelivery || $cause instanceof InvalidReservation) {
                throw $cause;
            }

            throw $failure;
        }
        if (!$transition instanceof WorkflowTransition) {
            throw new \LogicException('DBLayer returned an invalid workflow settlement result.');
        }

        return $transition;
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        self::validateReceive($queue, $limit, $visibilitySeconds);
        $now = $this->microseconds();
        $reservedUntil = Time::add($now, $visibilitySeconds);
        $token = ULID::generateMonotonic();

        /** @var list<array{id:mixed,message_id:mixed,payload:mixed,attempts:mixed}> $rows */
        $rows = $this->connection->transaction(function (Connection $connection) use (
            $queue,
            $limit,
            $now,
            $reservedUntil,
            $token,
        ): array {
            $lock = match ($connection->getDriverName()) {
                'mysql', 'pgsql' => ' FOR UPDATE SKIP LOCKED',
                'sqlite' => '',
                default => throw new \LogicException('Unsupported DBLayer queue driver.'),
            };
            $rows = $connection->select(
                "SELECT id, message_id, payload, attempts FROM {$this->table} WHERE queue_name = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?) ORDER BY available_at, id LIMIT {$limit}{$lock}",
                [$queue, $now, $now],
            );
            if ($rows === []) {
                return [];
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = self::rowString($row, 'id');
            }
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $connection->update(
                "UPDATE {$this->table} SET attempts = attempts + 1, reserved_until = ?, receipt = ? WHERE id IN ({$placeholders})",
                [$reservedUntil, $token, ...$ids],
            );

            return $rows;
        }, 3);

        $reservations = [];
        foreach ($rows as $row) {
            $id = self::rowString($row, 'id');
            $payload = self::rowString($row, 'payload');
            $messageId = self::rowString($row, 'message_id');
            $attempt = self::rowInt($row, 'attempts') + 1;
            $receipt = ReservationReceipt::encode($id, $token);

            try {
                $envelope = $this->serializer
                    ->decode($payload)
                    ->with(new AttemptStamp($attempt));
                $reservations[] = Reservation::decoded($receipt, $queue, $envelope, $attempt, $messageId);
            } catch (\Throwable $failure) {
                $reservations[] = Reservation::undecodable(
                    $receipt,
                    $queue,
                    DecodeFailure::fromThrowable($payload, $failure),
                    $attempt,
                    $messageId,
                );
            }
        }

        return $reservations;
    }

    public function reject(Reservation $reservation): void
    {
        $this->acknowledge($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Release delay must be a finite non-negative number.');
        }
        [$id, $token] = $this->receipt($reservation);
        $changed = $this->connection->update(
            "UPDATE {$this->table} SET available_at = ?, reserved_until = NULL, receipt = NULL WHERE id = ? AND queue_name = ? AND receipt = ?",
            [
                Time::add($this->microseconds(), $delaySeconds),
                $id,
                $reservation->queue,
                $token,
            ],
        );
        $this->assertChanged($changed, $reservation);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        QueueName::assert($queue);
        if (!$envelope->last(MessageIdStamp::class) instanceof MessageIdStamp) {
            $envelope = $envelope->with(new MessageIdStamp(ULID::generateMonotonic()));
        }
        $delay = $envelope->last(DelayStamp::class);
        $now = $this->microseconds();
        $this->connection->insert(
            "INSERT INTO {$this->table} (id, message_id, queue_name, payload, available_at, attempts, reserved_until, receipt, created_at) VALUES (?, ?, ?, ?, ?, 0, NULL, NULL, ?)",
            [
                ULID::generateMonotonic(),
                $envelope->last(MessageIdStamp::class)->id
                    ?? throw new \LogicException('Queued envelopes must have a message ID.'),
                $queue,
                $this->serializer->encode($envelope),
                Time::add(
                    $now,
                    $delay instanceof DelayStamp ? $delay->seconds : 0.0,
                ),
                $now,
            ],
        );

        return $envelope;
    }

    public function size(string $queue): int
    {
        QueueName::assert($queue);
        $now = $this->microseconds();
        $count = $this->connection->scalar(
            "SELECT COUNT(*) FROM {$this->table} WHERE queue_name = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)",
            [$queue, $now, $now],
        );
        if (
            (!is_int($count) && !is_string($count))
            || filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \UnexpectedValueException('Queue count must be an integer.');
        }

        return (int) $count;
    }

    public function supportsWorkflowStore(WorkflowStore $store): bool
    {
        return $store instanceof DBLayerWorkflowStore && $store->usesConnection($this->connection);
    }

    /** @param array<string, mixed> $row */
    private static function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (
            (!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \UnexpectedValueException(sprintf('Queue row "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $row */
    private static function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Queue row "%s" must be a string.', $key));
        }

        return $value;
    }

    private static function validateReceive(string $queue, int $limit, float $visibilitySeconds): void
    {
        QueueName::assert($queue);
        if ($limit < 1 || !is_finite($visibilitySeconds) || $visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Receive requires a queue, positive limit, and positive visibility timeout.',
            );
        }
        if ($limit > 1_000) {
            throw new \InvalidArgumentException('Receive limit cannot exceed 1000.');
        }
    }

    private function assertChanged(int $changed, Reservation $reservation): void
    {
        if ($changed !== 1) {
            throw new InvalidReservation(sprintf(
                'Reservation "%s" is no longer active.',
                $reservation->receipt,
            ));
        }
    }

    private function deleteReservation(Reservation $reservation, Connection $connection): void
    {
        [$id, $token] = $this->receipt($reservation);
        $deleted = $connection->delete(
            "DELETE FROM {$this->table} WHERE id = ? AND queue_name = ? AND receipt = ?",
            [$id, $reservation->queue, $token],
        );
        $this->assertChanged($deleted, $reservation);
    }

    private function microseconds(): int
    {
        return Time::fromDate($this->clock->now());
    }

    /** @return array{string,string} */
    private function receipt(Reservation $reservation): array
    {
        return ReservationReceipt::decode($reservation->receipt);
    }
}

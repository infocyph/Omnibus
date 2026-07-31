<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Transport\Duration;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final readonly class DBLayerTransport implements Transport
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
        [$id, $token] = $this->receipt($reservation);
        $deleted = $this->connection->update(
            "DELETE FROM {$this->table} WHERE id = ? AND queue_name = ? AND receipt = ?",
            [$id, $reservation->queue, $token],
        );
        $this->assertChanged($deleted, $reservation);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        self::validateReceive($queue, $limit, $visibilitySeconds);
        $now = $this->microseconds();
        $reservedUntil = $now + Duration::microseconds($visibilitySeconds, $now);
        $token = ULID::generateMonotonic();

        /** @var list<array{id:mixed,payload:mixed,attempts:mixed}> $rows */
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
                "SELECT id, payload, attempts FROM {$this->table} WHERE queue_name = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?) ORDER BY available_at, id LIMIT {$limit}{$lock}",
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
        });

        $reservations = [];
        foreach ($rows as $row) {
            $id = self::rowString($row, 'id');
            $payload = self::rowString($row, 'payload');
            $attempt = self::rowInt($row, 'attempts') + 1;
            $receipt = ReservationReceipt::encode($id, $token);

            try {
                $envelope = $this->serializer
                    ->decode($payload)
                    ->with(new AttemptStamp($attempt));
                $reservations[] = Reservation::decoded($receipt, $queue, $envelope, $attempt);
            } catch (\Throwable $failure) {
                $reservations[] = Reservation::undecodable(
                    $receipt,
                    $queue,
                    DecodeFailure::fromThrowable($payload, $failure),
                    $attempt,
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
                ($now = $this->microseconds()) + Duration::microseconds($delaySeconds, $now),
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
            "INSERT INTO {$this->table} (id, queue_name, payload, available_at, attempts, reserved_until, receipt, created_at) VALUES (?, ?, ?, ?, 0, NULL, NULL, ?)",
            [
                ULID::generateMonotonic(),
                $queue,
                $this->serializer->encode($envelope),
                $now + Duration::microseconds(
                    $delay instanceof DelayStamp ? $delay->seconds : 0.0,
                    $now,
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

    private function microseconds(): int
    {
        $now = $this->clock->now();

        return ((int) $now->format('U')) * 1_000_000 + (int) $now->format('u');
    }

    /** @return array{string,string} */
    private function receipt(Reservation $reservation): array
    {
        return ReservationReceipt::decode($reservation->receipt);
    }
}

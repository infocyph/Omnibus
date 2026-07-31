<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;

final readonly class DBLayerFailureStore implements FailureStore
{
    private string $table;

    public function __construct(
        private Connection $connection,
        private EnvelopeSerializer $serializer,
        string $table = 'omnibus_failures',
    ) {
        $this->table = SqlIdentifier::quote($table, $connection->getDriverName());
    }

    public function add(FailedMessage $failure): void
    {
        $kind = $failure->envelope === null ? 'raw' : 'envelope';
        $payload = $failure->envelope === null
            ? (string) $failure->payload
            : $this->serializer->encode($failure->envelope);

        $this->connection->transaction(function (Connection $connection) use (
            $failure,
            $kind,
            $payload,
        ): void {
            $connection->update("DELETE FROM {$this->table} WHERE id = ?", [$failure->id]);
            $connection->insert(
                "INSERT INTO {$this->table} (id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $failure->id,
                    $failure->queue,
                    $payload,
                    $kind,
                    $failure->payloadTruncated ? 1 : 0,
                    $failure->attempt,
                    self::dateToMicroseconds($failure->failedAt),
                    $failure->failureClass,
                    $failure->reason,
                ],
            );
        });
    }

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Failure list limit must be between 1 and 1000.');
        }
        $rows = $this->connection->select(
            "SELECT id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason FROM {$this->table} ORDER BY failed_at DESC, id LIMIT {$limit}",
        );

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function clear(): int
    {
        return $this->connection->update("DELETE FROM {$this->table}");
    }

    public function find(string $id): ?FailedMessage
    {
        $rows = $this->connection->select(
            "SELECT id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason FROM {$this->table} WHERE id = ?",
            [$id],
        );

        return isset($rows[0]) ? $this->hydrate($rows[0]) : null;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->connection->update(
            "DELETE FROM {$this->table} WHERE failed_at < ?",
            [self::dateToMicroseconds($before)],
        );
    }

    public function remove(string $id): bool
    {
        return $this->connection->update(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$id],
        ) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Failure row "%s" must be boolean.', $key));
        }

        return (bool) $value;
    }

    private static function dateToMicroseconds(\DateTimeImmutable $date): int
    {
        return ((int) $date->format('U')) * 1_000_000 + (int) $date->format('u');
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Failure row "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    private static function microsecondsToDate(int $microseconds): \DateTimeImmutable
    {
        $seconds = intdiv($microseconds, 1_000_000);
        $remainder = $microseconds % 1_000_000;
        $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $remainder));
        if (!$date instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('Stored failure timestamp is invalid.');
        }

        return $date;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Failure row "%s" must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FailedMessage
    {
        $id = self::string($row, 'id');
        $queue = self::string($row, 'queue_name');
        $payload = self::string($row, 'payload');
        $attempt = self::int($row, 'attempt');
        $failedAt = self::microsecondsToDate(self::int($row, 'failed_at'));
        $failureClass = self::string($row, 'failure_class');
        $reason = self::string($row, 'reason');

        $kind = self::string($row, 'payload_kind');
        if ($kind !== 'raw' && $kind !== 'envelope') {
            throw new \UnexpectedValueException(sprintf('Stored failure payload kind "%s" is invalid.', $kind));
        }
        if ($kind === 'envelope') {
            try {
                return FailedMessage::decoded(
                    $id,
                    $queue,
                    $this->serializer->decode($payload),
                    $attempt,
                    $failedAt,
                    $failureClass,
                    $reason,
                );
            } catch (\Throwable) {
                // Registry drift must not make the failure store unreadable.
            }
        }

        return FailedMessage::undecodable(
            $id,
            $queue,
            $payload,
            $attempt,
            $failedAt,
            $failureClass,
            $reason,
            self::bool($row, 'payload_truncated'),
        );
    }
}

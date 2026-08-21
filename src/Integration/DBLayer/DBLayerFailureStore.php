<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureNotFound;
use Infocyph\Omnibus\Failure\FailureRetryClaim;
use Infocyph\Omnibus\Failure\FailureRetryClaimUnavailable;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final readonly class DBLayerFailureStore implements FailureStore
{
    private string $table;

    public function __construct(
        private Connection $connection,
        private EnvelopeSerializer $serializer,
        private string $rawTable = 'omnibus_failures',
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->table = SqlIdentifier::quote($this->rawTable, $connection->getDriverName());
    }

    public function add(FailedMessage $failure): void
    {
        $kind = $failure->envelope === null ? 'raw' : 'envelope';
        $payload = $failure->envelope === null
            ? (string) $failure->payload
            : $this->serializer->encode($failure->envelope);

        $upserted = $this->connection->table($this->rawTable)->upsert(
            [
                'id' => $failure->id,
                'queue_name' => $failure->queue,
                'payload' => $payload,
                'payload_kind' => $kind,
                'payload_truncated' => $failure->payloadTruncated ? 1 : 0,
                'attempt' => $failure->attempt,
                'failed_at' => Time::fromDate($failure->failedAt),
                'failure_class' => $failure->failureClass,
                'reason' => $failure->reason,
            ],
            ['id'],
            [
                'queue_name',
                'payload',
                'payload_kind',
                'payload_truncated',
                'attempt',
                'failed_at',
                'failure_class',
                'reason',
            ],
        );
        if (!$upserted) {
            throw new \RuntimeException('DBLayer did not persist the failed message.');
        }
    }

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Failure list limit must be between 1 and 1000.');
        }
        $select = $this->connection->getDriverName() === 'mssql'
            ? "SELECT TOP ({$limit}) id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason FROM {$this->table} ORDER BY failed_at DESC, id"
            : "SELECT id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason FROM {$this->table} ORDER BY failed_at DESC, id LIMIT {$limit}";
        $rows = $this->connection->select($select);

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function claimRetry(string $id, float $leaseSeconds = 30.0): FailureRetryClaim
    {
        if (!is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Failure retry claim lease must be positive and finite.');
        }
        $now = Time::fromDate($this->clock->now());
        $token = ULID::generateMonotonic();
        $expiresAt = Time::add($now, $leaseSeconds);
        $result = $this->connection->transaction(function (Connection $connection) use (
            $id,
            $now,
            $token,
            $expiresAt,
        ): array {
            $changed = $connection->update(
                "UPDATE {$this->table} SET retry_status = 'retrying', retry_token = ?, retry_until = ? WHERE id = ? AND (retry_status = 'failed' OR (retry_status = 'retrying' AND retry_until <= ?))",
                [$token, $expiresAt, $id, $now],
            );
            $rows = $connection->select(
                "SELECT id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason, retry_status FROM {$this->table} WHERE id = ?",
                [$id],
            );

            return ['changed' => $changed, 'rows' => $rows];
        });
        if (!is_array($result)) {
            throw new \LogicException('DBLayer returned an invalid failure retry claim result.');
        }
        $changed = $result['changed'] ?? null;
        $rows = $result['rows'] ?? null;
        if (!is_int($changed) || !is_array($rows)) {
            throw new \LogicException('DBLayer returned malformed failure retry claim data.');
        }
        if ($changed !== 1) {
            if (!isset($rows[0]) || !is_array($rows[0])) {
                throw new FailureNotFound(sprintf('Failed message "%s" was not found.', $id));
            }

            throw new FailureRetryClaimUnavailable(sprintf(
                'Failed message "%s" is already being retried.',
                $id,
            ));
        }
        if (!isset($rows[0]) || !is_array($rows[0])) {
            throw new \LogicException('Claimed failure row was not returned by DBLayer.');
        }

        return new FailureRetryClaim(
            $this->hydrate(self::associative($rows[0])),
            $token,
            $expiresAt,
        );
    }

    public function clear(): int
    {
        return $this->connection->delete("DELETE FROM {$this->table}");
    }

    public function find(string $id): ?FailedMessage
    {
        $rows = $this->connection->select(
            "SELECT id, queue_name, payload, payload_kind, payload_truncated, attempt, failed_at, failure_class, reason FROM {$this->table} WHERE id = ?",
            [$id],
        );

        return isset($rows[0]) ? $this->hydrate($rows[0]) : null;
    }

    public function markRetrySent(FailureRetryClaim $claim): bool
    {
        return $this->connection->update(
            "UPDATE {$this->table} SET retry_status = 'sent', retry_until = NULL WHERE id = ? AND retry_status = 'retrying' AND retry_token = ?",
            [$claim->failure->id, $claim->token],
        ) === 1;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->connection->delete(
            "DELETE FROM {$this->table} WHERE failed_at < ?",
            [Time::fromDate($before)],
        );
    }

    public function releaseRetry(FailureRetryClaim $claim): bool
    {
        return $this->connection->update(
            "UPDATE {$this->table} SET retry_status = 'failed', retry_token = NULL, retry_until = NULL WHERE id = ? AND retry_status = 'retrying' AND retry_token = ?",
            [$claim->failure->id, $claim->token],
        ) === 1;
    }

    public function remove(string $id): bool
    {
        return $this->connection->delete(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$id],
        ) === 1;
    }

    public function removeRetried(FailureRetryClaim $claim): bool
    {
        return $this->connection->delete(
            "DELETE FROM {$this->table} WHERE id = ? AND retry_status = 'sent' AND retry_token = ?",
            [$claim->failure->id, $claim->token],
        ) === 1;
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private static function associative(array $row): array
    {
        $resolved = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Failure row keys must be strings.');
            }
            $resolved[$key] = $value;
        }

        return $resolved;
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

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Failure row "%s" must be an integer.', $key));
        }

        return (int) $value;
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
        $failedAt = Time::toDate(self::int($row, 'failed_at'));
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

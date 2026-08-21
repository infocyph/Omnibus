<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

final class QueueSchema
{
    /** @return list<string> */
    public static function statements(
        string $driver,
        string $queueTable = 'omnibus_messages',
        string $failureTable = 'omnibus_failures',
        string $workflowTable = 'omnibus_workflows',
        string $workflowItemTable = 'omnibus_workflow_items',
    ): array {
        $queue = SqlIdentifier::quote($queueTable, $driver);
        $failure = SqlIdentifier::quote($failureTable, $driver);
        $workflow = SqlIdentifier::quote($workflowTable, $driver);
        $workflowItem = SqlIdentifier::quote($workflowItemTable, $driver);
        $queueIndex = SqlIdentifier::quote(self::indexName($queueTable, 'ready'), $driver);
        $failedIndex = SqlIdentifier::quote(self::indexName($failureTable, 'failed'), $driver);
        $workflowItemIndex = SqlIdentifier::quote(self::indexName($workflowItemTable, 'pending'), $driver);
        $workflowClaimIndex = SqlIdentifier::quote(self::indexName($workflowItemTable, 'claims'), $driver);

        return match ($driver) {
            'mysql' => self::mysqlStatements(
                $queue,
                $failure,
                $workflow,
                $workflowItem,
                $queueIndex,
                $failedIndex,
                $workflowItemIndex,
                $workflowClaimIndex,
            ),
            'mariadb' => self::mariaDbStatements(
                $queue,
                $failure,
                $workflow,
                $workflowItem,
                $queueIndex,
                $failedIndex,
                $workflowItemIndex,
                $workflowClaimIndex,
            ),
            'pgsql' => self::postgresStatements(
                $queue,
                $failure,
                $workflow,
                $workflowItem,
                $queueIndex,
                $failedIndex,
                $workflowItemIndex,
                $workflowClaimIndex,
            ),
            'mssql' => self::sqlServerStatements(
                $queue,
                $failure,
                $workflow,
                $workflowItem,
                $queueIndex,
                $failedIndex,
                $workflowItemIndex,
                $workflowClaimIndex,
            ),
            'sqlite' => self::sqliteStatements(
                $queue,
                $failure,
                $workflow,
                $workflowItem,
                $queueIndex,
                $failedIndex,
                $workflowItemIndex,
                $workflowClaimIndex,
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'DBLayer queue schema does not support driver "%s".',
                $driver,
            )),
        };
    }

    private static function indexName(string $table, string $suffix): string
    {
        $normalized = str_replace('.', '_', $table) . '_' . $suffix . '_idx';
        if (strlen($normalized) > 63) {
            throw new \InvalidArgumentException('Queue table names must produce index names of at most 63 bytes.');
        }

        return $normalized;
    }

    /** @return list<string> */
    private static function mariaDbStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return self::mysqlFamilyStatements(
            $queue,
            $failure,
            $workflow,
            $workflowItem,
            $queueIndex,
            $failedIndex,
            $workflowItemIndex,
            $workflowClaimIndex,
        );
    }

    /** @return list<string> */
    private static function mysqlStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return self::mysqlFamilyStatements(
            $queue,
            $failure,
            $workflow,
            $workflowItem,
            $queueIndex,
            $failedIndex,
            $workflowItemIndex,
            $workflowClaimIndex,
        );
    }

    /** @return list<string> */
    private static function mysqlFamilyStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return [
            "CREATE TABLE {$queue} (id CHAR(26) PRIMARY KEY, message_id VARCHAR(191) NOT NULL, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, available_at BIGINT NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, reserved_until BIGINT NULL, receipt CHAR(26) NULL, created_at BIGINT NOT NULL)",
            "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
            "CREATE TABLE {$failure} (id VARCHAR(191) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, payload_kind VARCHAR(16) NOT NULL CHECK (payload_kind IN ('raw', 'envelope')), payload_truncated TINYINT(1) NOT NULL DEFAULT 0 CHECK (payload_truncated IN (0, 1)), attempt INT UNSIGNED NOT NULL CHECK (attempt > 0), failed_at BIGINT NOT NULL, failure_class VARCHAR(255) NOT NULL, reason TEXT NOT NULL, retry_status VARCHAR(16) NOT NULL DEFAULT 'failed' CHECK (retry_status IN ('failed', 'retrying', 'sent')), retry_token VARCHAR(191) NULL, retry_until BIGINT NULL, CHECK ((retry_status = 'failed' AND retry_token IS NULL AND retry_until IS NULL) OR (retry_status = 'retrying' AND retry_token IS NOT NULL AND retry_until IS NOT NULL) OR (retry_status = 'sent' AND retry_token IS NOT NULL AND retry_until IS NULL)))",
            "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
            "CREATE TABLE {$workflow} (id CHAR(26) PRIMARY KEY, kind VARCHAR(16) NOT NULL CHECK (kind IN ('batch', 'chain')), workflow_status VARCHAR(16) NOT NULL CHECK (workflow_status IN ('pending', 'running', 'completed', 'failed', 'cancelled')), total INT UNSIGNED NOT NULL CHECK (total > 0), succeeded INT UNSIGNED NOT NULL DEFAULT 0, failed INT UNSIGNED NOT NULL DEFAULT 0, cancelled INT UNSIGNED NOT NULL DEFAULT 0, CHECK (succeeded + failed + cancelled <= total))",
            "CREATE TABLE {$workflowItem} (workflow_id CHAR(26) NOT NULL, item_id CHAR(26) NOT NULL, message_id VARCHAR(191) NOT NULL, item_index INT UNSIGNED NOT NULL, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, item_status VARCHAR(16) NOT NULL CHECK (item_status IN ('pending', 'dispatching', 'dispatched', 'handled', 'succeeded', 'failed', 'cancelled')), dispatch_claim_token VARCHAR(191) NULL, dispatch_claim_until BIGINT NULL, handled_at BIGINT NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE KEY (workflow_id, item_index), UNIQUE KEY (message_id), FOREIGN KEY (workflow_id) REFERENCES {$workflow} (id) ON DELETE CASCADE)",
            "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            "CREATE INDEX {$workflowClaimIndex} ON {$workflowItem} (workflow_id, item_status, dispatch_claim_until, item_index)",
        ];
    }

    /** @return list<string> */
    private static function postgresStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return [
            "CREATE TABLE {$queue} (id CHAR(26) PRIMARY KEY, message_id VARCHAR(191) NOT NULL, queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, available_at BIGINT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0), reserved_until BIGINT NULL, receipt CHAR(26) NULL, created_at BIGINT NOT NULL)",
            "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
            "CREATE TABLE {$failure} (id VARCHAR(191) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, payload_kind VARCHAR(16) NOT NULL CHECK (payload_kind IN ('raw', 'envelope')), payload_truncated BOOLEAN NOT NULL DEFAULT FALSE, attempt INTEGER NOT NULL CHECK (attempt > 0), failed_at BIGINT NOT NULL, failure_class VARCHAR(255) NOT NULL, reason TEXT NOT NULL, retry_status VARCHAR(16) NOT NULL DEFAULT 'failed' CHECK (retry_status IN ('failed', 'retrying', 'sent')), retry_token VARCHAR(191) NULL, retry_until BIGINT NULL, CHECK ((retry_status = 'failed' AND retry_token IS NULL AND retry_until IS NULL) OR (retry_status = 'retrying' AND retry_token IS NOT NULL AND retry_until IS NOT NULL) OR (retry_status = 'sent' AND retry_token IS NOT NULL AND retry_until IS NULL)))",
            "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
            "CREATE TABLE {$workflow} (id CHAR(26) PRIMARY KEY, kind VARCHAR(16) NOT NULL CHECK (kind IN ('batch', 'chain')), workflow_status VARCHAR(16) NOT NULL CHECK (workflow_status IN ('pending', 'running', 'completed', 'failed', 'cancelled')), total INTEGER NOT NULL CHECK (total > 0), succeeded INTEGER NOT NULL DEFAULT 0 CHECK (succeeded >= 0), failed INTEGER NOT NULL DEFAULT 0 CHECK (failed >= 0), cancelled INTEGER NOT NULL DEFAULT 0 CHECK (cancelled >= 0), CHECK (succeeded + failed + cancelled <= total))",
            "CREATE TABLE {$workflowItem} (workflow_id CHAR(26) NOT NULL REFERENCES {$workflow} (id) ON DELETE CASCADE, item_id CHAR(26) NOT NULL, message_id VARCHAR(191) NOT NULL UNIQUE, item_index INTEGER NOT NULL CHECK (item_index >= 0), queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, item_status VARCHAR(16) NOT NULL CHECK (item_status IN ('pending', 'dispatching', 'dispatched', 'handled', 'succeeded', 'failed', 'cancelled')), dispatch_claim_token VARCHAR(191) NULL, dispatch_claim_until BIGINT NULL, handled_at BIGINT NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE (workflow_id, item_index))",
            "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            "CREATE INDEX {$workflowClaimIndex} ON {$workflowItem} (workflow_id, item_status, dispatch_claim_until, item_index)",
        ];
    }

    /** @return list<string> */
    private static function sqliteStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return [
            "CREATE TABLE {$queue} (id TEXT PRIMARY KEY, message_id TEXT NOT NULL, queue_name TEXT NOT NULL, payload TEXT NOT NULL, available_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0), reserved_until INTEGER NULL, receipt TEXT NULL, created_at INTEGER NOT NULL)",
            "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
            "CREATE TABLE {$failure} (id TEXT PRIMARY KEY, queue_name TEXT NOT NULL, payload TEXT NOT NULL, payload_kind TEXT NOT NULL CHECK (payload_kind IN ('raw', 'envelope')), payload_truncated INTEGER NOT NULL DEFAULT 0 CHECK (payload_truncated IN (0, 1)), attempt INTEGER NOT NULL CHECK (attempt > 0), failed_at INTEGER NOT NULL, failure_class TEXT NOT NULL, reason TEXT NOT NULL, retry_status TEXT NOT NULL DEFAULT 'failed' CHECK (retry_status IN ('failed', 'retrying', 'sent')), retry_token TEXT NULL, retry_until INTEGER NULL, CHECK ((retry_status = 'failed' AND retry_token IS NULL AND retry_until IS NULL) OR (retry_status = 'retrying' AND retry_token IS NOT NULL AND retry_until IS NOT NULL) OR (retry_status = 'sent' AND retry_token IS NOT NULL AND retry_until IS NULL)))",
            "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
            "CREATE TABLE {$workflow} (id TEXT PRIMARY KEY, kind TEXT NOT NULL CHECK (kind IN ('batch', 'chain')), workflow_status TEXT NOT NULL CHECK (workflow_status IN ('pending', 'running', 'completed', 'failed', 'cancelled')), total INTEGER NOT NULL CHECK (total > 0), succeeded INTEGER NOT NULL DEFAULT 0 CHECK (succeeded >= 0), failed INTEGER NOT NULL DEFAULT 0 CHECK (failed >= 0), cancelled INTEGER NOT NULL DEFAULT 0 CHECK (cancelled >= 0), CHECK (succeeded + failed + cancelled <= total))",
            "CREATE TABLE {$workflowItem} (workflow_id TEXT NOT NULL REFERENCES {$workflow} (id) ON DELETE CASCADE, item_id TEXT NOT NULL, message_id TEXT NOT NULL UNIQUE, item_index INTEGER NOT NULL CHECK (item_index >= 0), queue_name TEXT NOT NULL, payload TEXT NOT NULL, item_status TEXT NOT NULL CHECK (item_status IN ('pending', 'dispatching', 'dispatched', 'handled', 'succeeded', 'failed', 'cancelled')), dispatch_claim_token TEXT NULL, dispatch_claim_until INTEGER NULL, handled_at INTEGER NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE (workflow_id, item_index))",
            "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            "CREATE INDEX {$workflowClaimIndex} ON {$workflowItem} (workflow_id, item_status, dispatch_claim_until, item_index)",
        ];
    }

    /** @return list<string> */
    private static function sqlServerStatements(
        string $queue,
        string $failure,
        string $workflow,
        string $workflowItem,
        string $queueIndex,
        string $failedIndex,
        string $workflowItemIndex,
        string $workflowClaimIndex,
    ): array {
        return [
            "CREATE TABLE {$queue} (id CHAR(26) PRIMARY KEY, message_id NVARCHAR(191) NOT NULL, queue_name NVARCHAR(191) NOT NULL, payload NVARCHAR(MAX) NOT NULL, available_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0 CHECK (attempts >= 0), reserved_until BIGINT NULL, receipt CHAR(26) NULL, created_at BIGINT NOT NULL)",
            "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
            "CREATE TABLE {$failure} (id NVARCHAR(191) PRIMARY KEY, queue_name NVARCHAR(191) NOT NULL, payload NVARCHAR(MAX) NOT NULL, payload_kind NVARCHAR(16) NOT NULL CHECK (payload_kind IN ('raw', 'envelope')), payload_truncated BIT NOT NULL DEFAULT 0, attempt INT NOT NULL CHECK (attempt > 0), failed_at BIGINT NOT NULL, failure_class NVARCHAR(255) NOT NULL, reason NVARCHAR(MAX) NOT NULL, retry_status NVARCHAR(16) NOT NULL DEFAULT 'failed' CHECK (retry_status IN ('failed', 'retrying', 'sent')), retry_token NVARCHAR(191) NULL, retry_until BIGINT NULL, CHECK ((retry_status = 'failed' AND retry_token IS NULL AND retry_until IS NULL) OR (retry_status = 'retrying' AND retry_token IS NOT NULL AND retry_until IS NOT NULL) OR (retry_status = 'sent' AND retry_token IS NOT NULL AND retry_until IS NULL)))",
            "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
            "CREATE TABLE {$workflow} (id CHAR(26) PRIMARY KEY, kind NVARCHAR(16) NOT NULL CHECK (kind IN ('batch', 'chain')), workflow_status NVARCHAR(16) NOT NULL CHECK (workflow_status IN ('pending', 'running', 'completed', 'failed', 'cancelled')), total INT NOT NULL CHECK (total > 0), succeeded INT NOT NULL DEFAULT 0 CHECK (succeeded >= 0), failed INT NOT NULL DEFAULT 0 CHECK (failed >= 0), cancelled INT NOT NULL DEFAULT 0 CHECK (cancelled >= 0), CHECK (succeeded + failed + cancelled <= total))",
            "CREATE TABLE {$workflowItem} (workflow_id CHAR(26) NOT NULL, item_id CHAR(26) NOT NULL, message_id NVARCHAR(191) NOT NULL UNIQUE, item_index INT NOT NULL CHECK (item_index >= 0), queue_name NVARCHAR(191) NOT NULL, payload NVARCHAR(MAX) NOT NULL, item_status NVARCHAR(16) NOT NULL CHECK (item_status IN ('pending', 'dispatching', 'dispatched', 'handled', 'succeeded', 'failed', 'cancelled')), dispatch_claim_token NVARCHAR(191) NULL, dispatch_claim_until BIGINT NULL, handled_at BIGINT NULL, CONSTRAINT " . SqlIdentifier::quote(self::constraintName($workflowItem, 'pk'), 'mssql') . " PRIMARY KEY (workflow_id, item_id), CONSTRAINT " . SqlIdentifier::quote(self::constraintName($workflowItem, 'idx_uq'), 'mssql') . " UNIQUE (workflow_id, item_index), CONSTRAINT " . SqlIdentifier::quote(self::constraintName($workflowItem, 'workflow_fk'), 'mssql') . " FOREIGN KEY (workflow_id) REFERENCES {$workflow} (id) ON DELETE CASCADE)",
            "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            "CREATE INDEX {$workflowClaimIndex} ON {$workflowItem} (workflow_id, item_status, dispatch_claim_until, item_index)",
        ];
    }

    private static function constraintName(string $quotedTable, string $suffix): string
    {
        $table = trim(str_replace(['][', '[', ']'], ['_', '', ''], $quotedTable));

        return substr($table . '_' . $suffix, 0, 120);
    }
}

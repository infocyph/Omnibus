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

        return match ($driver) {
            'mysql' => [
                "CREATE TABLE {$queue} (id CHAR(26) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, available_at BIGINT NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, reserved_until BIGINT NULL, receipt CHAR(26) NULL, created_at BIGINT NOT NULL)",
                "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
                "CREATE TABLE {$failure} (id VARCHAR(191) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, payload_kind VARCHAR(16) NOT NULL, payload_truncated TINYINT(1) NOT NULL DEFAULT 0, attempt INT UNSIGNED NOT NULL, failed_at BIGINT NOT NULL, failure_class VARCHAR(255) NOT NULL, reason TEXT NOT NULL)",
                "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
                "CREATE TABLE {$workflow} (id CHAR(26) PRIMARY KEY, kind VARCHAR(16) NOT NULL, workflow_status VARCHAR(16) NOT NULL, total INT UNSIGNED NOT NULL, succeeded INT UNSIGNED NOT NULL DEFAULT 0, failed INT UNSIGNED NOT NULL DEFAULT 0, cancelled INT UNSIGNED NOT NULL DEFAULT 0)",
                "CREATE TABLE {$workflowItem} (workflow_id CHAR(26) NOT NULL, item_id CHAR(26) NOT NULL, item_index INT UNSIGNED NOT NULL, queue_name VARCHAR(191) NOT NULL, payload MEDIUMTEXT NOT NULL, item_status VARCHAR(16) NOT NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE KEY (workflow_id, item_index))",
                "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            ],
            'pgsql' => [
                "CREATE TABLE {$queue} (id CHAR(26) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, available_at BIGINT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0), reserved_until BIGINT NULL, receipt CHAR(26) NULL, created_at BIGINT NOT NULL)",
                "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
                "CREATE TABLE {$failure} (id VARCHAR(191) PRIMARY KEY, queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, payload_kind VARCHAR(16) NOT NULL, payload_truncated BOOLEAN NOT NULL DEFAULT FALSE, attempt INTEGER NOT NULL CHECK (attempt > 0), failed_at BIGINT NOT NULL, failure_class VARCHAR(255) NOT NULL, reason TEXT NOT NULL)",
                "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
                "CREATE TABLE {$workflow} (id CHAR(26) PRIMARY KEY, kind VARCHAR(16) NOT NULL, workflow_status VARCHAR(16) NOT NULL, total INTEGER NOT NULL CHECK (total > 0), succeeded INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0, cancelled INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE {$workflowItem} (workflow_id CHAR(26) NOT NULL, item_id CHAR(26) NOT NULL, item_index INTEGER NOT NULL, queue_name VARCHAR(191) NOT NULL, payload TEXT NOT NULL, item_status VARCHAR(16) NOT NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE (workflow_id, item_index))",
                "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            ],
            'sqlite' => [
                "CREATE TABLE {$queue} (id TEXT PRIMARY KEY, queue_name TEXT NOT NULL, payload TEXT NOT NULL, available_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0), reserved_until INTEGER NULL, receipt TEXT NULL, created_at INTEGER NOT NULL)",
                "CREATE INDEX {$queueIndex} ON {$queue} (queue_name, available_at, reserved_until)",
                "CREATE TABLE {$failure} (id TEXT PRIMARY KEY, queue_name TEXT NOT NULL, payload TEXT NOT NULL, payload_kind TEXT NOT NULL, payload_truncated INTEGER NOT NULL DEFAULT 0, attempt INTEGER NOT NULL CHECK (attempt > 0), failed_at INTEGER NOT NULL, failure_class TEXT NOT NULL, reason TEXT NOT NULL)",
                "CREATE INDEX {$failedIndex} ON {$failure} (failed_at)",
                "CREATE TABLE {$workflow} (id TEXT PRIMARY KEY, kind TEXT NOT NULL, workflow_status TEXT NOT NULL, total INTEGER NOT NULL CHECK (total > 0), succeeded INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0, cancelled INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE {$workflowItem} (workflow_id TEXT NOT NULL, item_id TEXT NOT NULL, item_index INTEGER NOT NULL, queue_name TEXT NOT NULL, payload TEXT NOT NULL, item_status TEXT NOT NULL, PRIMARY KEY (workflow_id, item_id), UNIQUE (workflow_id, item_index))",
                "CREATE INDEX {$workflowItemIndex} ON {$workflowItem} (workflow_id, item_status, item_index)",
            ],
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
}

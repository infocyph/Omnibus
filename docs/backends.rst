Durable backends
================

No durable backend initializes unless it is explicitly constructed.

.. list-table::
   :header-rows: 1

   * - Backend
     - Reservation
     - Batch
     - Delay
     - Depth
   * - DBLayer
     - Transaction and conditional receipt.
     - Up to 1000.
     - Timestamp.
     - Exact visible depth.
   * - Redis/Valkey
     - Atomic Lua scripts and conditional token.
     - Up to 1000.
     - Sorted-set score.
     - Exact visible/expired depth.
   * - AMQP
     - Provider adapter.
     - Declared capability.
     - Topology/provider dependent.
     - Provider declared.
   * - SQS
     - Receipt handle and visibility.
     - Provider maximum.
     - Native provider delay.
     - Approximate.

DBLayer
-------

Install DBLayer 4.1 (``infocyph/dblayer:^4.1``) and execute every statement
returned by ``QueueSchema::statements($driver)`` in an application migration.
Supported canonical drivers are ``mysql``, ``mariadb``, ``pgsql``, ``mssql``,
and ``sqlite``. MySQL and MariaDB are independent DBLayer drivers even though
they currently share an Omnibus schema family. The schema creates message,
failure, workflow, and workflow-item tables with indexes, state checks, and a
workflow-item foreign key.

The failure table includes the ``failed`` → ``retrying`` → ``sent`` claim
lifecycle. Existing pre-release schemas must be recreated or explicitly
migrated to add ``retry_status``, ``retry_token``, and ``retry_until`` before
using ``FailureManager::retry()``. Adapters never perform schema migration at
runtime.

.. code-block:: php

   foreach (QueueSchema::statements('pgsql') as $statement) {
       $connection->statement($statement);
   }

Adapters never create or alter tables during dispatch.

MySQL, MariaDB, and PostgreSQL reservations and workflow claims use ``FOR
UPDATE SKIP LOCKED``. SQL Server uses ``TOP`` with ``UPDLOCK``, ``READPAST``,
and ``ROWLOCK`` hints. SQLite uses short DBLayer-owned transactions with
retryable lock-contention handling; Omnibus does not issue unmanaged PDO
transaction commands or expose driver-specific transaction knobs. A
reservation receipt contains both row ID and token; acknowledge, reject, and
release are conditional on the current token. An expired stale worker cannot
settle a newer reservation.

After a worker crash, allow ``reserved_until`` to expire. A later receive
reclaims the row and increments the attempt. Do not manually clear receipts
while workers are active.

``AfterCommitDispatcher`` registers dispatch through DBLayer's
``Connection::afterCommit()``. It runs immediately outside a transaction, after
the outermost successful commit inside a transaction, and never after rollback.

Redis and Valkey
----------------

``RedisTransport`` keeps one queue's keys in a shared Redis hash tag. Queue
names and prefixes cannot contain braces, preventing caller-controlled cluster
slot changes. Send, receive/reclaim, acknowledge/reject, release, and size use
atomic Lua scripts.

.. code-block:: php

   $client = new CallbackRedisClient(
       static fn (string $command, string ...$arguments): mixed =>
           $redis->rawCommand($command, ...$arguments),
   );

   $transport = new RedisTransport(
       $client,
       $serializer,
       $clock,
       prefix: 'my-application',
   );

A missing payload hash entry is surfaced as a poison reservation so its
reservation can be terminally settled; it is not stranded silently. Malformed,
negative, fractional, and overflowing integer responses are rejected.

AMQP and SQS
------------

``AmqpBackend`` and ``SqsBackend`` are provider-neutral contracts. An adapter
translates delivery tags or receipt handles, visibility, dead-letter behavior,
delays, prefetch/batches, and depth.

``BrokerCapabilities`` declares:

* delayed delivery support;
* delayed release support;
* exact versus approximate depth;
* maximum receive batch.

Omnibus rejects unsupported requests and provider over-delivery. AMQP delayed
delivery may require broker topology or a plugin. SQS FIFO deduplication,
credentials, SDK retries, and client lifecycle remain adapter concerns.

Operational recovery
--------------------

Never delete only one Redis hash/sorted set from a queue record set. Never
rewrite database receipt fields while workers are active. Restore backend
connectivity, wait for visibility expiry, and let normal receive reclaim work.
Inspect the failure store for terminal payloads rather than editing queue
records.

Operating without Redis
-----------------------

Redis is optional. For a deployment that cannot operate a Redis or Valkey
service, use ``DBLayerTransport`` with SQLite, MySQL, MariaDB, PostgreSQL, or
SQL Server. SQLite is the zero-service durable option and retains reservation
receipts, visibility, delayed delivery, workflow state, and terminal failures
in one local database. It is appropriate for one host whose worker processes
share a reliable local filesystem. Omnibus acceptance tests exercise concurrent
SQLite consumers, while DBLayer transaction retries absorb short writer
contention. Keep reservation and settlement transactions short, and use a
server database when several hosts consume the same queue or sustained write
contention is expected.

``InMemoryTransport`` requires no service but is process-local and intentionally
non-durable. It is suitable for tests, same-process deferred work, and workloads
that may be lost when the process exits.

Memcached is not a queue transport. Its eviction model and primitive set cannot
provide Omnibus's durable message record, ordered delayed set, reservation
receipt, visibility reclaim, and atomic settlement guarantees. Do not emulate a
reliable queue by storing messages as cache entries.

See :ref:`complete-sqlite-durable-queue`,
:ref:`redis-or-valkey-transport`, and
:ref:`memcached-uniqueness-with-a-durable-database-queue` for copyable
compositions.

# Omnibus — DBLayer 5.0 Integration Plan

## Status

**Target package:** `infocyph/omnibus`  
**Target Omnibus release:** **2.5.0**  
**DBLayer baseline:** **5.0**  
**Dependency role:** optional production adapter, development/test dependency in Omnibus  
**Compatibility direction:** preserve Omnibus public messaging/transport/workflow contracts

---

## 1. Objective

Upgrade Omnibus's DBLayer integration from DBLayer 4.1 to DBLayer 5.0 and realign responsibility boundaries so DBLayer owns database infrastructure behavior while Omnibus continues to own messaging and workflow semantics.

Primary goals:

1. Upgrade the tested DBLayer constraint to `^5.0`.
2. Keep DBLayer optional.
3. Remove duplicate transaction retry orchestration from Omnibus.
4. Make queue receive and workflow claim sizes respect DBLayer 5 driver-aware bind limits.
5. Retain explicit queue/workflow state-machine SQL where it is the clearest and safest abstraction.
6. Revalidate all DBLayer-backed transports/stores across supported database drivers.
7. Strengthen transaction, after-commit, contention, and durability tests.
8. Update all documentation from DBLayer 4.1 to DBLayer 5.
9. Release the integration as Omnibus **2.5.0**.

---

## 2. Architectural Boundary

Omnibus and DBLayer responsibilities must remain explicit.

### DBLayer owns

```text
connection lifecycle
driver capability knowledge
bind-parameter limits
query execution
query retry facilities
transaction begin/commit/rollback
savepoints
transaction deadlock/retry behavior
after-commit callback lifecycle
read/write connection semantics
database exceptions
```

### Omnibus owns

```text
message IDs
queue semantics
reservation receipts
visibility timeout
acknowledge/release/reject semantics
workflow state transitions
workflow claims
failure-store semantics
message retry semantics
serialization
delivery guarantees
idempotency expectations
```

Do not duplicate a DBLayer infrastructure behavior inside Omnibus when DBLayer 5 already provides it.

---

## 3. Composer Dependency Update

Update `composer.json`.

### Target

```json
"require-dev": {
    "infocyph/dblayer": "^5.0"
}
```

Keep DBLayer out of normal `require`.

Update `suggest` to clearly state that DBLayer is required only when constructing DBLayer-backed adapters.

Suggested wording:

```json
"infocyph/dblayer": "Required for DB queue, workflow, failure-store, and after-commit integrations; tested against DBLayer 5.x."
```

Requirements:

- keep `infocyph/phpforge` as `dev-main@dev`;
- preserve DBLayer as optional;
- remove all stale 4.1 wording;
- refresh dependency lock data if tracked.

---

## 4. DBLayer Integration Surface

Review the complete integration directory:

```text
src/Integration/DBLayer/
    AfterCommitDispatcher.php
    DBLayerFailureStore.php
    DBLayerTransport.php
    DBLayerWorkflowStore.php
    QueueSchema.php
    SqlIdentifier.php
```

Also inspect all tests, benchmarks, documentation, and CI that reference these classes.

Search the full repository for:

```text
Infocyph\DBLayer
DBLayer
dblayer
4.1
transaction(
withQueryRetryPolicy
safeBatchSize
IN (
array_fill
FOR UPDATE
SKIP LOCKED
UPDLOCK
READPAST
```

No DBLayer-related path should be skipped.

---

## 5. Keep DBLayer Optional

The DBLayer adapters remain production-capable optional integrations.

Do not:

- make DBLayer a core dependency;
- reference DBLayer classes from core transport contracts;
- make `MessageBus` aware of DBLayer;
- make `WorkflowStore` depend on DBLayer types;
- make DBLayer necessary for in-memory, Redis, broker, AMQP, SQS, or synchronous operation.

The package must still install and run without DBLayer unless a DBLayer adapter is constructed.

---

## 6. Preserve `AfterCommitDispatcher`

The current concept is correct:

```php
$this->connection->afterCommit(function () use ($message): void {
    $this->bus->dispatch($message);
});
```

DBLayer 5 owns the full callback lifecycle across nested transactions.

Keep Omnibus's adapter intentionally small.

Do not add another internal queue of after-commit messages.

### Required tests

Cover:

```text
outside transaction
    → dispatch immediately

top-level commit
    → dispatch once

top-level rollback
    → do not dispatch

nested transaction commit + outer commit
    → dispatch once after outer commit

nested transaction rollback
    → callbacks registered in rolled-back scope are discarded

multiple callbacks
    → preserve expected ordering

callback throws after commit
    → database commit remains committed and failure is observable
```

---

## 7. Remove Duplicate Transaction Retry Loops

### Problem

`DBLayerTransport` currently layers an Omnibus retry loop around:

```php
$connection->transaction($operation, 3);
```

DBLayer 5 already implements bounded retry handling in its transaction engine.

Retaining both creates retry amplification and unclear ownership.

A three-attempt outer loop around a three-attempt DBLayer transaction may cause up to nine transaction executions.

### Required direction

Transaction retry belongs to DBLayer.

Replace custom outer transaction retry orchestration with direct DBLayer usage.

Preferred shape:

```php
private function transaction(callable $operation): mixed
{
    return $this->connection->transaction(
        $operation,
        self::TRANSACTION_ATTEMPTS,
    );
}
```

Or call the DBLayer method directly at the transaction sites if that is clearer.

### Remove/reduce

Where no longer needed:

```text
Omnibus transaction retry loop
duplicate retryability checks
duplicate transaction backoff
duplicate transaction jitter
duplicate transaction retry constants
DriverProfile dependency used only for transaction replay decisions
```

Retain Omnibus-specific retry logic only when it represents **message/queue semantics**, not database deadlock recovery.

---

## 8. Transaction Exception Semantics

After simplifying retry ownership, recheck every `TransactionException` catch.

Domain/state exceptions must still escape correctly.

Examples include:

```text
WorkflowInconsistentDelivery
InvalidReservation
WorkflowNotFound
stale workflow claim errors
```

If DBLayer wraps callback failures inside a transaction exception, unwrap/rethrow only where Omnibus already needs to preserve a public domain exception.

Do not broadly unwrap all DBLayer exceptions.

Acceptance criteria:

- retryable database transaction failures are retried by DBLayer;
- non-retryable Omnibus domain failures are not repeatedly replayed;
- final failure type remains appropriate for Omnibus callers.

---

## 9. Keep Query-Level Retry Separate

`retryMutation()` currently uses DBLayer's query retry policy for isolated mutations.

Do not merge transaction retry and single-query retry into one mechanism.

### Rule

Use DBLayer transaction retry for multi-statement atomic work.

Use DBLayer query retry only for a single statement when replay is safe and its SQL predicate makes repeated execution idempotent/conditional.

Examples of potentially safe conditional settlement mutations include queries guarded by:

```text
reservation receipt
claim token
current retry status
expected item status
```

Audit every `withQueryRetryPolicy()` call site.

For each one, document in code/tests why replaying the statement cannot produce duplicate semantic effects.

---

## 10. Queue Receive Bind-Limit Safety

### Problem

`DBLayerTransport::receive()` accepts up to 1,000 rows.

Reservation then performs an update similar to:

```sql
UPDATE ...
SET attempts = attempts + 1,
    reserved_until = ?,
    receipt = ?
WHERE id IN (?, ?, ...)
```

Bindings:

```text
2 fixed bindings
+ N message IDs
```

A request for 1,000 rows may exceed a driver/security bind limit.

### Required change

Calculate the effective receive batch before selecting rows:

```php
$effectiveLimit = $connection->safeBatchSize(
    parametersPerRow: 1,
    fixedBindings: 2,
    requested: $limit,
);
```

Use `$effectiveLimit` in `selectReservableRows()`.

### Important

Do **not**:

1. select 1,000 rows;
2. split the reservation update into several chunks.

Reservation acquisition should remain one coherent transaction.

Returning fewer than the caller's requested maximum is acceptable.

The `limit` parameter represents a maximum, not a guaranteed count.

---

## 11. Queue Receive Validation

Retain the user-facing upper bound:

```text
1 <= limit <= 1000
```

Then derive:

```text
effective limit = min(
    caller limit,
    DBLayer safe bind capacity
)
```

Do not expose driver bind limits through the public Omnibus API.

A caller requesting 1,000 should receive up to the maximum that can be atomically reserved safely by the active connection.

---

## 12. Workflow Claim Bind-Limit Safety

`DBLayerWorkflowStore` performs a claim update similar to:

```sql
UPDATE ...
SET item_status = 'dispatching',
    dispatch_claim_token = ?,
    dispatch_claim_until = ?
WHERE workflow_id = ?
  AND item_status = 'pending'
  AND item_id IN (?, ?, ...)
```

Bindings:

```text
3 fixed bindings
+ N item IDs
```

Calculate:

```php
$effectiveLimit = $connection->safeBatchSize(
    parametersPerRow: 1,
    fixedBindings: 3,
    requested: $limit,
);
```

Use the effective limit **before selecting claimable rows**.

Then preserve the existing invariant:

```php
$changed === count($rows)
```

Do not split one workflow claim into several update chunks after selecting a larger row set.

Claim acquisition should remain atomic.

---

## 13. Audit Every Dynamic Binding Path

Search the entire DBLayer integration for:

```text
IN (...)
NOT IN (...)
array_fill(... '?')
whereIn()
bulk inserts
bulk upserts
multi-row inserts
dynamic OR lists
```

For each query:

1. count parameters per logical row;
2. count fixed bindings;
3. verify the maximum row count;
4. use `safeBatchSize()` if the count can grow.

No Omnibus-owned static bind-limit map should be introduced.

DBLayer 5 owns this knowledge.

---

## 14. Keep `DBLayerFailureStore` Low-Level

Do not rewrite the failure store using `TableRepository`.

The store's operations are stateful infrastructure actions:

```text
failure upsert
retry claim
retry release
mark retry sent
conditional deletion
failure pruning
```

Existing explicit `Connection` / `QueryBuilder` calls are appropriate.

Do not replace `prune()` with DBLayer `RepositoryPruner`.

A direct `DELETE ... WHERE failed_at < ?` is already clear and optimal.

---

## 15. Keep `DBLayerWorkflowStore` Explicit

Do not convert workflow state transitions into repository hooks, relations, or model-like abstractions.

The workflow store contains important atomic state-machine SQL for:

```text
pending
dispatching
dispatched
handled
succeeded
failed
cancelled
```

and invariants around:

```text
claim tokens
claim expiry
workflow counters
chain cancellation
terminal workflow states
row locking
```

These transitions should remain visible and auditable.

Repository abstraction would hide concurrency semantics rather than improve them.

---

## 16. Keep `DBLayerTransport` Explicit

Queue SQL should remain purpose-built.

Do not introduce a generic repository for queue rows.

Transport correctness depends on explicit conditions involving:

```text
available_at
reserved_until
receipt
attempts
queue_name
visibility
locking
```

The DBLayer 5 Repository system is useful for reusable table policies but is not required for these state-machine operations.

---

## 17. Retain Driver-Specific Locking SQL

Keep existing database-specific reservation/claim locking unless DBLayer 5 exposes a public abstraction proven to generate equivalent SQL and guarantees.

Expected strategies remain equivalent to:

```text
MySQL / MariaDB
    FOR UPDATE SKIP LOCKED

PostgreSQL
    FOR UPDATE SKIP LOCKED

SQL Server
    UPDLOCK + READPAST + ROWLOCK

SQLite
    transaction-level behavior without SKIP LOCKED
```

Correct concurrency semantics take priority over API uniformity.

Do not replace this with a generic fluent call unless all supported drivers preserve the current guarantees.

---

## 18. Queue Schema Strategy

Keep `QueueSchema` explicit in this release.

DBLayer 5 has stronger schema/migration facilities, but moving Omnibus queue tables into them is a separate refactor.

Only consider replacing explicit DDL if DBLayer can represent the current schema without loss of:

```text
CHECK constraints
compound unique constraints
foreign keys
ON DELETE behavior
driver-specific text/blob sizes
SQL Server types
MySQL/MariaDB types
SQLite constraints
index ordering
```

The DBLayer 5 upgrade must not expand scope by rewriting a working portable queue schema.

---

## 19. No Query Result Caching for Coordination State

Do not enable DBLayer query caching for:

```text
queue receive
queue size used for coordination
reservation validation
workflow status reads used by transitions
workflow claims
failure retry claims
retry lease state
```

These reads participate in coordination.

Freshness and transaction visibility are more important than result-cache speed.

---

## 20. Keep Plain Arrays in Hot Paths

Do not adopt DBLayer 5 collections for queue/workflow hot paths.

Current operations are bounded and immediately hydrate Omnibus objects.

Preferred:

```text
DB rows
    → arrays
    → Reservation / WorkflowState / FailedMessage
```

Avoid extra collection allocation and abstraction.

---

## 21. Read/Write Affinity

Audit all reads that participate in a mutation or state transition.

They must read from the writer connection when stale replica data could violate correctness.

Particularly inspect:

```text
reservation settlement
workflow claim state
workflow item status
atomic workflow settlement
failure retry claim
post-mutation verification
```

Preserve any current writer-specific helper such as `writerSelect()` where necessary.

Do not accidentally replace writer-affine reads with normal replica-eligible QueryBuilder reads during the DBLayer 5 refactor.

---

## 22. After-Commit + Queue Integration

Verify that queue dispatch after commit works correctly when:

```text
dispatch callback sends to DBLayerTransport
dispatch callback sends to another transport
outer transaction rolls back
nested transaction commits but outer transaction rolls back
multiple messages are queued after commit
```

DBLayer 5's after-commit infrastructure should be the sole transaction lifecycle source.

Omnibus must not mark a message as dispatched before the database commit actually succeeds.

---

## 23. DBLayer 5 Driver Matrix

Run the durable DB integration against all currently supported backends:

```text
SQLite
MySQL
MariaDB
PostgreSQL
SQL Server
```

At minimum test:

### Queue

- send;
- receive;
- acknowledge;
- reject;
- release;
- delayed availability;
- visibility expiration;
- stale receipt protection;
- attempts increment;
- queue size;
- competing consumers.

### Failure store

- add/upsert;
- find;
- list;
- retry claim;
- claim collision;
- claim expiry;
- release retry;
- mark retry sent;
- remove;
- prune.

### Workflow

- create batch;
- create chain;
- claim pending;
- claim expiry/recovery;
- confirm dispatch;
- release claim;
- mark handled;
- succeed;
- fail;
- cancel;
- state counters;
- finalization;
- chain cancellation behavior;
- atomic settlement.

### Transaction lifecycle

- retryable deadlock/contention;
- non-retryable failure;
- nested transaction behavior;
- after-commit behavior;
- rollback.

---

## 24. Low Bind-Limit Integration Tests

Create at least one DBLayer integration configuration with a deliberately low:

```php
'security' => [
    'max_params' => 16,
],
```

or another suitably small value.

Verify:

```text
receive(1000)
    → safely caps the atomic reservation batch

claimPending(..., 1000)
    → safely caps the atomic claim batch
```

Assertions should establish:

```text
generated binding count <= effective DBLayer limit
```

Do not assert a vendor-specific magic number.

---

## 25. Retry Amplification Regression Test

Add a test specifically proving that Omnibus no longer multiplies DBLayer transaction retries.

Given:

```text
transaction attempts = 3
```

a retryable failure must execute the transactional callback at most:

```text
3 times
```

not:

```text
9 times
```

Test both:

- eventually succeeds on a later allowed attempt;
- exhausts all attempts.

Also verify a non-retryable Omnibus domain exception is not replayed.

---

## 26. Query Retry Regression Tests

For each single-query retry path retained in Omnibus:

- force one retryable failure;
- verify the operation is replayed only according to the DBLayer query policy;
- prove idempotent/conditional semantics prevent duplicate settlement;
- verify stale receipt/token conditions still fail correctly.

Do not test implementation details more deeply than needed, but establish that query retry cannot acknowledge or release the same reservation twice.

---

## 27. Contention Testing

Re-run and extend DB contention scenarios.

Measure:

```text
reservation throughput
successful claims/sec
deadlock count
transaction retries
p50 latency
p95 latency
p99 latency
stale settlement rejection
duplicate delivery rate
workflow consistency
```

The key expected improvement is reduced retry amplification after delegating transaction retry to DBLayer.

No durability guarantee may be weakened to improve benchmark numbers.

---

## 28. Soak Testing

Run existing soak workloads after the upgrade:

```text
consumer soak
durable queue soak
workflow soak
database contention benchmark
parallel SQLite worker test
```

Acceptance criteria:

- no unbounded memory growth;
- no stuck reservations;
- no workflow counter drift;
- no duplicate terminal transitions;
- no unexpected retry storms;
- no leaked transaction state;
- no stale receipt acceptance.

---

## 29. Runtime State Isolation

Ensure DBLayer test setup/teardown resets:

```text
connections
transactions
retry policies
cache hooks
telemetry
query deadlines
query cancellation callbacks
query comments
```

Tests that switch drivers or connection configurations must not inherit runtime state from prior tests.

---

## 30. DBLayer 5 Repository Features — Deliberate Non-Adoption

DBLayer 5 now includes a much richer repository layer.

Do **not** adopt these in Omnibus DB integrations merely because they are available:

```text
TableRepository
RepositoryDefinition
RepositoryQuery
repository relations
repository casts
repository global scopes
repository pruning
repository collections
```

The Omnibus DBLayer integration is primarily a transactional state machine.

Explicit low-level SQL remains the better abstraction for locking, claims, receipts, conditional state transitions, and atomic counters.

Repository adoption can be reconsidered only for future read-heavy administrative views, not the transport hot path.

---

## 31. Documentation Updates

Review and update:

```text
README.md
docs/backends.rst
docs/integration.rst
docs/operations.rst
docs/performance.rst
docs/testing.rst
docs/upgrading.rst
composer.json
```

Remove every DBLayer 4.1 reference.

Document DBLayer 5 ownership boundaries:

```text
DBLayer
    driver behavior
    bind sizing
    transaction retries
    after-commit callbacks
    DB execution

Omnibus
    message semantics
    reservations
    workflows
    failures
    delivery guarantees
```

Document that DBLayer remains optional.

---

## 32. Upgrade Notes

Add an Omnibus **2.5.0** section to `docs/upgrading.rst`.

Suggested topics:

### DBLayer baseline

```text
DBLayer 4.1 → DBLayer 5.x
```

### Transaction retry ownership

Omnibus no longer wraps DBLayer transaction retries with a second transaction retry loop.

### Adaptive atomic batch sizing

Queue receive and workflow claim operations now cap dynamic ID batches according to DBLayer 5's effective bind limits.

### Public API

No application-level migration is expected for:

```text
MessageBus
Transport
WorkflowStore
FailureStore
Consumer
Worker
```

Applications using the DBLayer adapter should update their DBLayer installation to 5.x.

---

## 33. Versioning

### Dependency

```text
infocyph/dblayer
^4.1 → ^5.0
```

### Omnibus release

Target:

```text
2.5.0
```

Rationale:

- DBLayer remains optional;
- Omnibus core contracts do not require a breaking change;
- transport/store public APIs remain stable;
- backend behavior and reliability materially improve;
- adaptive bind sizing and retry ownership are meaningful integration changes;
- a minor release is appropriate.

---

## 34. Likely Files to Change

Implementation should inspect the full repository, but the expected primary files are:

```text
composer.json

src/Integration/DBLayer/AfterCommitDispatcher.php
src/Integration/DBLayer/DBLayerTransport.php
src/Integration/DBLayer/DBLayerFailureStore.php
src/Integration/DBLayer/DBLayerWorkflowStore.php
src/Integration/DBLayer/QueueSchema.php
src/Integration/DBLayer/SqlIdentifier.php

tests/Integration/DBLayerAfterCommitTest.php
tests/Integration/DBLayerTransportTest.php
tests/Integration/DurableDriverMatrixTest.php
tests/Integration/ParallelSQLiteWorkerTest.php

benchmarks/db-contention.php
benchmarks/durable-soak.php
benchmarks/workflow-soak.php
benchmarks/consumer-soak.php

README.md
docs/backends.rst
docs/integration.rst
docs/operations.rst
docs/performance.rst
docs/testing.rst
docs/upgrading.rst

.github/workflows/security-standards.yml
```

CI changes are required only where the database matrix/version setup references the old DBLayer baseline.

---

## 35. Explicit Non-Goals

Do **not**:

- make DBLayer mandatory;
- redesign Omnibus core contracts;
- convert queue rows into repository entities;
- add identity maps or Active Record behavior;
- use DBLayer relations for workflows;
- enable DBLayer query caching for coordination state;
- convert queue results to collections;
- replace correct driver-specific locking without equivalent guarantees;
- rewrite QueueSchema solely to use DBLayer migrations;
- retain a second transaction retry loop around DBLayer's own retrying transaction engine;
- add an Omnibus-owned table of per-driver bind limits.

---

## 36. Acceptance Criteria

The Omnibus DBLayer 5 upgrade is complete only when all are true:

- [ ] `infocyph/dblayer` uses `^5.0` in `require-dev`.
- [ ] DBLayer remains optional.
- [ ] `suggest` text references DBLayer 5.x.
- [ ] all DBLayer 4.1 documentation is removed.
- [ ] `AfterCommitDispatcher` relies on DBLayer's after-commit lifecycle.
- [ ] nested after-commit behavior is tested.
- [ ] outer Omnibus transaction retry amplification is removed.
- [ ] DBLayer transaction retry is the sole DB deadlock/replay owner.
- [ ] non-retryable Omnibus domain exceptions are not replayed unnecessarily.
- [ ] queue receive uses `safeBatchSize()` with correct fixed-bind count.
- [ ] workflow claims use `safeBatchSize()` with correct fixed-bind count.
- [ ] all dynamic `IN`/binding paths are audited.
- [ ] low-`max_params` tests prove safe behavior.
- [ ] receive/claim batching stays atomic.
- [ ] DBLayerFailureStore remains explicit and correct.
- [ ] DBLayerWorkflowStore state transitions remain explicit.
- [ ] driver-specific lock semantics remain correct.
- [ ] coordination queries remain uncached.
- [ ] writer-affine reads remain writer-affine.
- [ ] SQLite integration passes.
- [ ] MySQL integration passes.
- [ ] MariaDB integration passes.
- [ ] PostgreSQL integration passes.
- [ ] SQL Server integration passes.
- [ ] retry amplification regression test proves max configured attempts only.
- [ ] contention tests pass.
- [ ] durable soak passes.
- [ ] workflow soak passes.
- [ ] parallel SQLite worker test passes.
- [ ] PHPForge CI passes.
- [ ] documentation includes Omnibus 2.5.0 upgrade notes.
- [ ] release notes identify DBLayer 5 as the supported/tested database integration baseline.

---

## 37. Recommended Implementation Order

1. Update Composer DBLayer constraint to `^5.0`.
2. Run the existing test suite unchanged to establish the DBLayer 5 compatibility baseline.
3. Audit all DBLayer imports and integration files.
4. Remove duplicated outer transaction retry orchestration.
5. Reconcile domain-exception handling with DBLayer 5 transaction wrapping.
6. Add `safeBatchSize()` to queue receive.
7. Add `safeBatchSize()` to workflow claims.
8. Audit every other dynamic parameter-count query.
9. Add low-bind-limit tests.
10. Expand after-commit nested transaction tests.
11. Add retry amplification regression tests.
12. Run the full database driver matrix.
13. Run contention and soak benchmarks.
14. Audit writer/read-affinity behavior.
15. Update README and DB integration documentation.
16. Add Omnibus 2.5.0 upgrade notes.
17. Run PHPForge CI/static/security checks.
18. Prepare the **2.5.0** release.

---

## 38. Final Design Rule

Omnibus should consume DBLayer 5 as a **database infrastructure engine**, not as an ORM or application-domain repository layer.

The desired relationship is:

```text
Omnibus state machine
        ↓
explicit DB operations
        ↓
DBLayer 5 connection/query/transaction infrastructure
        ↓
database driver
```

DBLayer owns the mechanics of executing database work safely.

Omnibus owns the semantics that make those database operations a reliable message queue and workflow engine.

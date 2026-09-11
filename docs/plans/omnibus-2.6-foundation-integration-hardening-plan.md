# Omnibus 2.6 — Foundation Integration, WorkerPool Lifecycle & Runtime Hardening Plan

## Status

Target release: **Omnibus 2.6**

Baseline:

- PHP: `^8.4`
- UID: `^5.0`
- CacheLayer reference integration: raise to `^3.4`
- DBLayer reference integration: raise to `^5.1`
- PHPForge: keep `dev-main@dev`
- Foundation integration target: `infocyph/foundation` Point **26.8**

This release is an additive hardening and ownership-alignment pass. Omnibus remains a framework-agnostic messaging/event/queue library. Foundation must consume Omnibus-owned generic messaging/runtime mechanics rather than maintaining Foundation-only substitutes.

---

## 1. Goals

1. Align Omnibus's optional CacheLayer and DBLayer integration suites with the versions currently consumed by Foundation.
2. Make `WorkerPool` lifecycle-aware so host runtimes can supply heartbeat and cooperative stop checks without wrapping the pool in their own signal watchdog.
3. Harden `pcntl`/`posix` supervision semantics: wait/reap behavior, signal handling, shutdown escalation, child process state, and restart behavior.
4. Preserve the correct post-fork ownership model for DB connections, Redis clients, broker connections, CacheLayer resources, sockets, locks, and other process-bound state.
5. Keep durable DBLayer-backed Omnibus services bound to the exact `Connection` instance required for queue/workflow transaction semantics.
6. Remove generic Omnibus supervision mechanics from Foundation where they currently exist only because Omnibus lacks the corresponding API.
7. Define a clean future extraction boundary for a lower-level Runwire/process-runtime library without making that future library a prerequisite for Omnibus 2.6.
8. Add focused tests and benchmarks proving the worker lifecycle and persistent-process behavior remain deterministic, bounded, leak-free, and low-overhead.

## 2. Non-goals

Omnibus 2.6 must **not** become a general process-execution or sandbox library.

Do not add:

- arbitrary command execution APIs;
- shell command wrappers;
- `exec`, `system`, `shell_exec`, `passthru`, or arbitrary `proc_open` orchestration;
- `pcntl_exec`-based user command execution;
- UID/GID/chroot/seccomp-style sandbox policy;
- arbitrary uploaded-code execution;
- Foundation-specific configuration parsing;
- Foundation container/runtime/release-generation concepts;
- Foundation deployment/process registry ownership;
- DBLayer connection creation/pooling/lifecycle ownership;
- CacheLayer connection/configuration ownership.

Omnibus workers execute trusted application code. A forked worker is not a security sandbox.

---

## 3. Dependency alignment

### 3.1 CacheLayer

Update the Omnibus development/reference integration floor:

```json
"infocyph/cachelayer": "^3.4"
```

Update all Composer `suggest` text and documentation that still refers to CacheLayer 3.1.x/3.2.x testing.

Review all CacheLayer integrations against 3.4:

- overlap protection;
- detached leases;
- uniqueness;
- rate limiting;
- circuit breaker state;
- lock refresh/loss behavior;
- atomic counter use;
- key generation and normalization.

Do not make CacheLayer a mandatory runtime dependency unless a public Omnibus feature intrinsically requires it. Existing in-memory/native alternatives remain valid.

### 3.2 DBLayer

Raise the development/reference integration floor:

```json
"infocyph/dblayer": "^5.1"
```

Retest all DB-backed components against DBLayer 5.1:

- `DBLayerTransport`;
- `DBLayerWorkflowStore`;
- `DBLayerFailureStore`;
- `AfterCommitDispatcher`;
- `QueueSchema`;
- contention and durable-driver test matrices.

Prefer DBLayer 5.1 instance-native `Connection` APIs and capability helpers where applicable.

Do **not** replace exact concrete connections with a process-global/static connection lookup.

---

## 4. Preserve exact DBLayer connection ownership

The DBLayer integration is intentionally different from request-time validation adapters such as ReqShield.

Omnibus durable services should continue to own/use an exact DBLayer `Connection` instance for their lifetime inside the worker process.

This is required for:

- atomic queue reservation/settlement;
- same-connection workflow transitions;
- transaction-bound `afterCommit()` dispatch;
- driver-specific transaction/locking behavior;
- predictable worker-process resource ownership.

### Required invariant

A pooled worker must be created in this order:

```text
parent process remains resource-clean
        ↓
fork
        ↓
child process starts
        ↓
child application/runtime boots
        ↓
child opens DBLayer/Redis/broker/cache resources
        ↓
Omnibus transport/store/consumer graph is built
        ↓
worker begins consuming
```

Never create DBLayer connections, Redis/broker sockets, CacheLayer network clients, or similar process-bound resources in the pool parent and then reuse them in children.

### DBLayer integration review

Keep the existing same-connection assertion for atomic workflow settlement.

Verify DBLayer 5.1 compatibility for:

- `Connection::safeBatchSize()` usage;
- `Connection::table()` usage;
- transaction retries;
- transaction exception unwrapping;
- driver profile/capability checks;
- locking clauses;
- SQL Server/MySQL/MariaDB/PostgreSQL/SQLite differences;
- bind-parameter limits;
- failure-store retry claims;
- workflow dispatch claims.

Do not introduce Foundation-aware connection resolvers into Omnibus DB integration classes.

---

## 5. WorkerPool lifecycle support

### 5.1 Problem

`Worker` already supports `WorkerLifecycle` with:

```php
heartbeat(): void;
stopRequested(): bool;
```

`WorkerPool` does not expose equivalent parent-supervisor lifecycle integration.

Frameworks therefore need to wrap `WorkerPool::run()` with their own periodic signal/watchdog mechanism to:

- heartbeat an external process registry;
- detect deployment/runtime stop requests;
- call `WorkerPool::requestStop()`.

That is generic pool-supervision behavior and belongs in Omnibus.

### 5.2 Reuse `WorkerLifecycle`

Prefer reusing the existing `WorkerLifecycle` contract rather than creating another nearly identical interface.

Extend `WorkerPool` with an optional lifecycle:

```php
public function __construct(
    callable $workerFactory,
    int $concurrency = 1,
    int $maximumRestarts = 5,
    float $restartBackoffSeconds = 0.25,
    float $shutdownGraceSeconds = 30.0,
    ?WorkerLifecycle $lifecycle = null,
    float $lifecycleIntervalSeconds = 1.0,
)
```

Exact constructor shape may be refined during implementation, but avoid configuration-object proliferation unless it materially simplifies the class.

### 5.3 Required lifecycle semantics

The parent pool supervisor must periodically:

1. call `heartbeat()`;
2. call `stopRequested()`;
3. if stop is requested, transition once into normal `requestStop()` shutdown;
4. continue reaping children while shutdown proceeds;
5. preserve the configured graceful-shutdown deadline and force-kill escalation.

Lifecycle exceptions should escape to the caller after Omnibus has initiated child shutdown/draining.

Lifecycle polling must not require Foundation-specific types.

### 5.4 Avoid Foundation-owned `SIGALRM`

The final API must allow Foundation to remove its `WorkerManager::watchPool()` alarm wrapper entirely.

Foundation should be able to call simply:

```php
$pool = new WorkerPool(
    workerFactory: $workerFactory,
    concurrency: $concurrency,
    maximumRestarts: $maximumRestarts,
    restartBackoffSeconds: $restartBackoff,
    shutdownGraceSeconds: $shutdownGrace,
    lifecycle: $foundationLifecycle,
);

$pool->run();
```

Omnibus then owns the mechanics required to periodically service that lifecycle while supervising children.

---

## 6. Replace blocking supervision with lifecycle-capable waiting

The current parent supervisor uses blocking `pcntl_wait()`.

For external lifecycle polling, prefer a bounded non-blocking supervision loop based on `pcntl_waitpid(..., WNOHANG)` rather than introducing a second alarm signal solely to wake the parent.

Recommended behavior:

```text
loop
  ├─ reap all exited children with waitpid(..., WNOHANG)
  ├─ process restart/recycle state
  ├─ run lifecycle heartbeat/stop check when interval elapsed
  ├─ if stopping: enforce graceful deadline / SIGKILL escalation
  ├─ if no immediate work: short bounded sleep
  └─ repeat
```

Use monotonic time (`hrtime`) for supervisor deadlines/intervals.

Avoid an unnecessarily hot polling loop. A sleep in the low-millisecond range is appropriate for process supervision; lifecycle callbacks should run at their configured interval, not on every poll.

This removes the need for `SIGALRM` entirely while still allowing prompt child reaping and host-runtime stop detection.

---

## 7. `pcntl_wait*()` correctness hardening

Handle wait results deliberately.

For blocking/non-blocking wait calls:

```text
pid > 0
    child was reaped

pid == 0
    no child state change (valid for WNOHANG)

pid == -1
    PCNTL_EINTR
        retry / continue normal supervision

    ECHILD
        reconcile tracked children; fail closed if internal state says live children remain

    other error
        throw a runtime supervision exception
```

Do not silently `continue` forever on an unexpected `-1` result.

Add tests that simulate/reproduce interruption and child-table exhaustion where practical.

Ensure no code path can leave the parent in an infinite busy loop because the OS has no reapable children while Omnibus still believes children exist.

---

## 8. Signal handling hardening

### 8.1 Keep signal handlers minimal

Signal callbacks should preferably mutate small in-process flags only.

Do not perform full shutdown orchestration from inside a PHP signal handler.

Preferred pattern:

```text
SIGTERM/SIGINT handler
        ↓
set stop flag
        ↓
normal supervisor loop observes flag
        ↓
requestStop()
        ↓
SIGTERM children
        ↓
graceful deadline
        ↓
SIGKILL remaining children
```

This makes shutdown behavior easier to reason about and avoids reentrant supervisor work from asynchronous callbacks.

### 8.2 Preserve and restore caller state

Continue preserving/restoring:

- previous SIGTERM handler;
- previous SIGINT handler;
- previous `pcntl_async_signals()` mode;
- any additional Omnibus-owned signal state introduced by this release.

Restoration must run on:

- normal completion;
- lifecycle failure;
- spawn failure;
- restart-budget failure;
- unexpected supervision error.

### 8.3 Signal constants

Where practical, use defined signal constants (`SIGTERM`, `SIGINT`, `SIGKILL`) rather than hardcoded numeric values, while retaining predictable support checks on environments where constants/functions are unavailable.

---

## 9. Child process normalization

Before invoking the worker factory inside a forked child, normalize the process state Omnibus itself changed in the parent.

At minimum:

- reset Omnibus-owned signal handlers to defaults;
- unblock Omnibus-owned blocked signals;
- normalize inherited asynchronous signal handling to the expected child state;
- cancel/reset any Omnibus-owned timers/alarms if such mechanisms remain anywhere;
- clear parent-only `WorkerPool` tracking state from the child code path;
- do not retain parent shutdown deadlines/restart bookkeeping as operational child state.

Do not attempt to reset arbitrary application/global OS state that Omnibus does not own.

Foundation remains responsible for ensuring its application graph is safe to fork before the pool starts.

---

## 10. Fork-safety contract

Add explicit documentation for the worker factory contract.

The callable passed to `WorkerPool` is invoked **inside each child after fork** and must construct process-bound resources there.

Document examples of resources that must normally be child-created:

- DBLayer connections and leases;
- PDO connections;
- Redis/Valkey clients;
- Memcached clients where connection state may be process-bound;
- AMQP/SQS/broker network clients;
- open sockets;
- HTTP connection pools;
- lock handles;
- process-scoped telemetry exporters;
- file descriptors with mutable shared offsets when unsafe to inherit.

The pool cannot inspect an arbitrary host application's container/configuration graph to prove fork safety. That remains the host framework's responsibility.

---

## 11. Restart and recycling semantics

Preserve the distinction between:

- clean worker recycle/exit;
- expected SIGTERM during shutdown;
- worker crash;
- parent-requested stop;
- restart-budget exhaustion.

### Clean recycle

A worker that exits cleanly because it reached:

- maximum messages;
- maximum runtime;
- memory limit;
- memory-growth limit;
- lifecycle stop boundary;

should be replaceable without consuming the crash restart budget while the pool remains active.

### Crash restart

Unexpected crashes consume the per-slot restart budget.

Keep restart backoff bounded and deterministic enough to avoid hot restart storms.

Review whether restart budget should reset immediately on a single clean exit or only after a successful running interval/message cycle. Prefer the simpler current behavior unless testing reveals crash-loop masking.

### Spawn failure

If `pcntl_fork()` fails:

- stop spawning additional children;
- request shutdown of already-created children;
- reap them;
- propagate a clear parent-side failure.

---

## 12. Graceful shutdown and escalation

Keep the existing two-phase shutdown model:

```text
stop requested
    ↓
SIGTERM all tracked children
    ↓
wait until shutdownGraceSeconds
    ↓
SIGKILL remaining children
    ↓
reap all children
```

Harden edge cases:

- child exits between selection and `posix_kill()`;
- `posix_kill()` returns false because process disappeared;
- PID removed from tracking before escalation;
- repeated `requestStop()` calls;
- stop requested before `run()`;
- stop requested during child spawn;
- lifecycle throws while shutdown is already active;
- parent receives SIGINT/SIGTERM during graceful shutdown.

A disappeared child must not be treated as a fatal signalling failure if it can be reconciled by reaping/state refresh.

---

## 13. Worker lifecycle review

Keep `Worker`'s cooperative lifecycle model.

Review and preserve:

- heartbeat before processing starts;
- stop check before first receive;
- heartbeat after each consumed batch;
- stop check between batches;
- heartbeat around idle sleep boundaries;
- message-bound prefetch clipping;
- runtime limit;
- absolute memory limit;
- memory-growth limit;
- signal-handler restoration.

Do not push Foundation process-registry concepts into `Worker`.

### Optional small improvement

If useful for deterministic testing/performance, consider isolating the sleep/backoff operation behind a tiny internal helper or injectable sleeper. Do not add a public abstraction unless tests demonstrate real value.

---

## 14. Foundation ownership migration

After Omnibus 2.6 is released, Foundation Point 26.8 should consume the new API.

### Move generic behavior down to Omnibus

Foundation should remove its pool-specific alarm/watchdog implementation:

```text
WorkerManager::watchPool()
```

The generic behavior currently implemented there belongs to Omnibus:

- periodic parent lifecycle invocation;
- stop-check servicing while the pool supervises children;
- transition into `requestStop()`.

### Keep Foundation-specific behavior in Foundation

Do **not** move the following to Omnibus:

- `RuntimeProcessRegistry`;
- `RuntimeControl` tokens;
- release-generation replacement detection;
- active-generation verification;
- Foundation release manifest checks;
- `assertPoolParentClean()` container inspection;
- Foundation-specific `assertForkSafeConfig()` logic;
- Foundation application boot/reboot rules;
- Foundation `DBLayerFactory` selection;
- Foundation CacheLayer factory/lock configuration;
- Foundation worker route/config loading;
- Foundation DI/service-provider graph;
- Foundation execution-scope adaptation;
- HTTP/application exception mapping.

Foundation supplies these policies to Omnibus through its worker factory and `WorkerLifecycle` implementation.

---

## 15. Foundation messaging classes review

### Keep in Foundation

Keep these framework-specific integration pieces:

- `MessagingServiceProvider`;
- `MessagingGraphFactory`;
- `MessagingRuntimeResolver`;
- `ConsumerFactory`;
- `OmnibusWorkerFactory`;
- `InterMixExecutionScope`;
- Foundation worker configuration loading;
- Foundation container resolution of handlers/listeners/middleware.

They translate Foundation application configuration/DI/runtime semantics into Omnibus objects and therefore do not belong in Omnibus.

### Job middleware duplication review

Foundation currently has its own `Job`, `JobContext`, `JobMiddleware`, and `JobMiddlewarePipeline` layered over Omnibus `HandlerMiddleware`/`HandlerContext`.

Do **not** add a Foundation-style `Job` abstraction to Omnibus.

During Foundation 26.8 cleanup, evaluate whether Foundation can simplify that layer by directly using Omnibus handler middleware for jobs where possible.

This is a Foundation simplification opportunity, not an Omnibus 2.6 release blocker.

---

## 16. Runwire future boundary

Omnibus 2.6 should continue to implement its process pool directly with `pcntl`/`posix`.

Do not block this release on a new process library.

When a Runwire-style library exists later, only generic OS process mechanics should move there.

### Future Runwire ownership

Possible Runwire-owned mechanics:

- `fork()` wrapper;
- child PID tracking primitives;
- wait/reap helpers;
- signal registration/restoration;
- signal-mask manipulation;
- process-group signaling;
- graceful/forced termination primitive;
- monotonic process deadlines;
- clean post-fork signal state;
- low-level process capability detection.

### Omnibus remains owner of

- queue worker concurrency;
- worker slots;
- queue-worker factory semantics;
- worker recycling;
- crash restart budgets;
- restart backoff policy;
- message-consumer lifecycle;
- queue shutdown semantics;
- message retries/failures;
- queue/workflow integration.

Desired eventual dependency direction:

```text
Foundation
    ↓
Omnibus
    ↓
Runwire
    ↓
pcntl / posix / OS
```

Not:

```text
Runwire → Omnibus
```

and not:

```text
Omnibus → Foundation
```

---

## 17. Security boundary

State explicitly in documentation:

> WorkerPool isolates processes for concurrency and lifecycle management; it does not provide a security sandbox.

A worker inherits the executable/runtime capabilities available to the PHP child process unless the host environment has restricted them externally.

Omnibus must not expose a generic API that turns arbitrary message payloads into shell commands.

Safe model:

```text
trusted typed message
    ↓
registered application handler
    ↓
authorized application operation
```

Unsafe model to avoid:

```text
untrusted string
    ↓
message
    ↓
shell/process execution
```

If Foundation later supports controlled external process execution, that capability should be mediated by Runwire/Foundation security policy, not by the Omnibus message transport.

---

## 18. CacheLayer integration hardening

Rescan all existing CacheLayer adapters against 3.4 and verify no Foundation-specific equivalents are being maintained.

Focus on:

- uniqueness leases;
- overlap locks;
- detached lease ownership;
- lease refresh/loss detection;
- rate-limit counters;
- circuit breaker state transitions;
- TTL behavior;
- key namespace isolation;
- failure modes when backend operations fail;
- behavior under long-running workers.

Ensure persistent workers do not retain stale lock/lease handles across independent message executions.

Do not silently downgrade coordination guarantees if CacheLayer operations fail.

---

## 19. DBLayer 5.1 integration hardening

Run all durable queue/workflow/failure tests with DBLayer 5.1.

Review for opportunities to use the latest structured APIs without changing semantics merely for style.

Validate:

- safe bind sizing for receive/claim operations;
- exact transaction ownership;
- retry behavior on transient transaction failures;
- driver-specific row locking;
- reservation token correctness;
- stale reservation release;
- atomic workflow acknowledgement;
- workflow item claims;
- failure retry claims;
- after-commit dispatch;
- database disconnect/error propagation;
- no accidental static `DB` facade reliance;
- no process-parent DB connection inheritance in pool integration examples/tests.

Do not swallow DBLayer infrastructure errors and reinterpret them as normal queue misses.

---

## 20. Tests

### 20.1 WorkerPool lifecycle tests

Add tests proving:

- pool lifecycle heartbeat is invoked while children are running;
- lifecycle can request stop without OS signal input;
- lifecycle stop reaches all child workers;
- lifecycle exception shuts down/reaps children before escaping;
- lifecycle polling does not consume restart budget;
- heartbeat interval is bounded and not called on every tight supervisor iteration.

### 20.2 Wait/reap tests

Cover:

- normal child exit;
- clean recycle;
- crash exit;
- SIGTERM exit;
- SIGKILL escalation;
- interrupted waits;
- no-child reconciliation;
- multiple children exiting near-simultaneously;
- no zombies after successful run/failure/shutdown.

### 20.3 Fork-safety tests

Where practical, prove worker factory construction occurs inside the child by recording:

- parent PID;
- factory PID;
- worker execution PID.

Factory PID and worker PID must match and differ from the parent.

Add an integration example/test that opens a DBLayer connection inside the child factory rather than before `WorkerPool` construction.

### 20.4 Signal tests

Verify:

- parent handlers are restored;
- async-signal mode is restored;
- child sees normalized Omnibus-owned signal state;
- repeated stop signals are idempotent;
- shutdown still completes when a child ignores SIGTERM.

### 20.5 DBLayer 5.1 matrix

Retest existing DB integration on supported drivers/CI capability:

- SQLite;
- MySQL/MariaDB;
- PostgreSQL;
- SQL Server where available.

Retain contention/parallel worker testing.

### 20.6 CacheLayer 3.4 matrix

Retest supported coordination backends and in-memory fixtures, including long-worker lease behavior.

---

## 21. Benchmarks and soak tests

Add/extend benchmarks for:

### Worker overhead

Compare:

```text
Consumer direct loop
vs
single Worker
vs
WorkerPool concurrency=1
vs
WorkerPool concurrency=N
```

Measure parent supervisor CPU usage while workers are idle.

### Lifecycle overhead

Measure `WorkerPool` with:

- no lifecycle;
- lifecycle enabled at 1-second interval;
- repeated idle workers.

Lifecycle support must not create a busy-spin supervisor.

### Recycling

Soak repeated worker recycle cycles and verify:

- stable parent memory;
- no accumulating child PID state;
- no zombie processes;
- bounded restart metadata;
- predictable shutdown latency.

### Durable contention

Continue DB queue/workflow contention benchmarks under DBLayer 5.1.

Record baseline before/after figures so Foundation can attribute any runtime change to Omnibus 2.6 rather than its own integration layer.

---

## 22. Documentation updates

Update:

- worker/pool operations docs;
- integration docs;
- performance docs;
- testing docs;
- architecture docs;
- Composer `suggest` descriptions;
- upgrade notes.

Document clearly:

1. `Worker` vs `WorkerPool` responsibilities;
2. optional `WorkerLifecycle` for both single and pooled workers;
3. parent-process resource cleanliness;
4. child-only process-bound resource construction;
5. graceful shutdown/restart semantics;
6. `pcntl`/`posix` requirements;
7. process isolation is not security sandboxing;
8. Runwire 1.0 extraction boundary.

---

## 23. Foundation 26.8 migration checklist

Once Omnibus 2.6 is released:

1. Raise Foundation's optional Omnibus version from `^2.5` to `^2.6`.
2. Replace the Foundation `SIGALRM`-based `watchPool()` wrapper with an Omnibus `WorkerLifecycle` supplied directly to `WorkerPool`.
3. Delete obsolete `WorkerManager::watchPool()` logic.
4. Retain `assertPoolParentClean()` because it knows Foundation's container/process-bound service graph.
5. Retain Foundation release-generation and runtime-control stop predicates.
6. Feed those predicates through the lifecycle object rather than through Foundation-owned signal polling.
7. Keep child application boot inside the `WorkerPool` worker factory.
8. Prove no DBLayer/CacheLayer/TalkingBytes/transport network resources exist in the pool parent before fork.
9. Benchmark direct Omnibus worker/pool behavior against Foundation's wrapper overhead.
10. Run persistent/Fiber/request isolation tests around message handlers.

---

## 24. Acceptance criteria

Omnibus 2.6 is ready when all of the following are true:

- Composer/test metadata targets CacheLayer `^3.4` and DBLayer `^5.1`.
- Existing optional integrations still remain optional runtime dependencies.
- All DBLayer 5.1 durable queue/workflow/failure/after-commit tests pass.
- CacheLayer 3.4 coordination tests pass.
- `WorkerPool` accepts an external lifecycle without Foundation-specific dependencies.
- An external stop request can stop a pool without a Foundation `SIGALRM` wrapper.
- Pool lifecycle heartbeat operates while children are active/idle.
- Parent supervisor does not busy-spin.
- Unexpected `pcntl_wait*()` failures cannot produce infinite loops.
- Parent SIGTERM/SIGINT handlers and async mode are restored after run.
- child process Omnibus-owned signal state is normalized before worker construction.
- graceful stop escalates to SIGKILL after the configured deadline.
- all children are reaped on normal exit, lifecycle failure, spawn failure, restart exhaustion, and forced shutdown.
- clean worker recycling does not consume crash restart budget.
- process-bound resources are documented/tested as child-created.
- exact DBLayer connection semantics remain intact for atomic workflow settlement and `afterCommit()`.
- Omnibus exposes no arbitrary process/shell execution API.
- Foundation can delete its pool-specific signal watchdog after upgrading.
- Worker/pool benchmarks show no unacceptable regression from lifecycle polling.

---

## 25. Recommended implementation order

1. Dependency/docs alignment: CacheLayer 3.4 and DBLayer 5.1.
2. Add `WorkerPool` lifecycle support.
3. Refactor parent supervision to lifecycle-capable `waitpid(..., WNOHANG)` loop.
4. Harden wait error handling.
5. Reduce signal callbacks to flag mutation and centralize shutdown orchestration in the supervisor loop.
6. Normalize Omnibus-owned child signal/process state after fork.
7. Harden spawn/reap/restart/shutdown edge cases.
8. Add lifecycle, signal, fork-safety, zombie/reaping, and restart tests.
9. Re-run CacheLayer/DBLayer integrations and contention suites.
10. Add worker/pool/lifecycle benchmarks and soak runs.
11. Update architecture/operations/upgrading documentation.
12. Release Omnibus 2.6.
13. Upgrade Foundation to `^2.6` and remove `WorkerManager::watchPool()`.
14. Complete Foundation Point 26.8 acceptance and benchmark gates.
15. Track Runwire delegation separately as a later ecosystem-level process-runtime task.

---

## 26. Final ownership model

```text
Foundation
 ├─ application configuration
 ├─ DI/container resolution
 ├─ runtime/process registry
 ├─ release-generation stop policy
 ├─ pre-fork application cleanliness checks
 ├─ DB/cache/broker configuration and child boot
 └─ Foundation execution-scope integration
        │
        ▼
Omnibus 2.6
 ├─ message/event routing
 ├─ consumer execution
 ├─ retries and failure storage
 ├─ workflows
 ├─ scheduling/broadcasting
 ├─ transport abstractions
 ├─ DBLayer/CacheLayer messaging integrations
 ├─ Worker lifecycle
 └─ WorkerPool queue-supervision policy
        │
        ▼
current implementation
 pcntl / posix
        │
        ▼
target generic process layer
 Runwire 1.0
        │
        ▼
OS
```

The important boundary is:

> Foundation owns application/runtime policy; Omnibus owns messaging and queue-worker policy; Runwire 1.0 owns reusable low-level OS process mechanics.

That keeps each library focused, removes duplicate supervision logic from Foundation, and avoids turning Omnibus into a generic process-execution or security-sandbox package.

---

---

## 27. Runwire 1.0 process-supervision integration

### 27.1 Revised ownership

## Omnibus owns

- message `Consumer`;
- message `Worker` loop;
- queue polling/prefetch;
- envelope/handler execution;
- retry/failure/settlement semantics;
- workflow semantics;
- message-driven recycling decisions;
- queue-specific lifecycle and health;
- durable transport/store behavior;
- public queue-worker API/facade.

## Runwire owns

- `pcntl_fork()`;
- child PID/slot bookkeeping at generic process level;
- signal registration/dispatch;
- `pcntl_wait*()` / child reaping;
- `posix_kill()` / process-group signalling;
- generic graceful → forced process termination;
- generic restart/backoff mechanics;
- process generation/reload primitives;
- clean child process bootstrap/normalization;
- generic worker-group lifecycle/ticking/status.

## Foundation owns

- application worker configuration;
- Foundation `RuntimeControl` / process-registry semantics;
- release-generation policy;
- application boot after fork;
- InterMix execution scope per message;
- application DB/cache/broker profile selection;
- app-specific heartbeat/readiness/stop meaning.

Hard invariant:

> Omnibus 2.6 should not ship a second independently maintained raw `pcntl`/`posix` supervisor once released Runwire 1.0 provides the required worker-group semantics.

---

### 27.2 Current collision to remove

Current `Omnibus\Consumer\WorkerPool` directly owns generic Unix process mechanics including:

```text
pcntl_fork
pcntl_wait
pcntl_waitpid
pcntl_signal
pcntl_signal_get_handler
pcntl_async_signals
pcntl_sigprocmask
posix_kill
```

It also tracks child PIDs/slots, restart budgets, graceful shutdown deadlines and kill escalation.

Those mechanics made sense before Runwire existed. With Runwire launching as the Infocyph process/runtime layer, retaining a separate implementation creates:

- duplicated signal semantics;
- duplicated wait/reap edge handling;
- duplicated restart/shutdown behavior;
- separate child-normalization rules;
- duplicated Foundation integration;
- higher security/correctness maintenance cost.

The goal is to remove that duplication without moving queue semantics into Runwire.

---

### 27.3 Preserve `WorkerPool` as Omnibus public API/facade

Do not force Omnibus users to understand Runwire supervision just to consume messages.

Preferred direction:

```text
Omnibus\Consumer\WorkerPool
    queue-facing configuration + Worker factory
              ↓
    Runwire\Supervisor / WorkerGroup
              ↓
          OS process layer
```

`WorkerPool` can remain the stable Omnibus API while delegating the generic process engine internally.

Its queue-oriented options may remain:

- concurrency;
- maximum crash restarts;
- restart backoff;
- shutdown grace;
- Omnibus lifecycle adapter;
- worker factory.

Exact internal Runwire mapping must use the final Runwire 1.0 public API rather than pre-emptively exposing unstable lower-level types in Omnibus signatures.

---

### 27.4 Dependency decision

Recommended for Omnibus 2.6:

```json
"infocyph/runwire": "^1.0"
```

as a normal production dependency **if `WorkerPool` delegates to Runwire in the released build**.

Reasoning:

- Omnibus already publicly advertises process-based `WorkerPool` behavior;
- retaining an optional fallback direct-`pcntl` supervisor defeats the ownership consolidation;
- Runwire is a low-level Infocyph runtime dependency, not a framework dependency;
- one process engine is easier to secure/test than two.

If packaging work proves Runwire can be truly optional without retaining any duplicate supervisor—for example `WorkerPool` itself moves to a separately installable integration package—then optionality may be reconsidered. Do **not** preserve a second raw `pcntl` engine merely to avoid the dependency.

Runwire must never depend on Omnibus.

Release order:

```text
Runwire 1.0
   ↓
Omnibus 2.6 final
   ↓
Foundation 3 final integration
```

Development branches may temporarily target Runwire development state; final Omnibus 2.6 must require released `^1.0`.

---

### 27.5 `Worker` remains Omnibus-owned

Do not move `Omnibus\Consumer\Worker` into Runwire.

It owns message-domain behavior such as:

- consuming batches;
- heartbeat around message polling;
- queue idle backoff;
- max messages;
- message-worker max runtime;
- memory/recycle policy tied to queue worker lifecycle;
- prefetch clipping;
- visibility/lease behavior;
- clean exit after message-worker limits.

Runwire simply runs/supervises the child process executing that Worker.

Conceptually:

```text
Runwire child starts
       ↓
Omnibus worker factory creates process-bound resources
       ↓
Omnibus Worker::run()
       ↓
clean return / exception / process termination
       ↓
Runwire observes process outcome
```

---

### 27.6 Clean exit vs crash semantics

Omnibus must communicate enough intent to Runwire so queue-worker recycling is not misclassified as a crash.

Preserve semantic distinction between:

```text
clean Omnibus worker recycle
clean parent/requested shutdown
unexpected worker exception/crash
process killed by external signal
Runwire supervisor failure
```

Preferred implementation:

- Omnibus Worker returns/terminates with a clean child outcome when message/runtime/memory recycle conditions are reached;
- Runwire treats clean worker exit as replaceable while group remains active;
- clean recycle does not consume the crash restart budget;
- unexpected non-zero/abnormal termination consumes configured restart budget;
- Foundation release drain/stop prevents replacement when group is stopping.

Avoid encoding a large queue-specific reason protocol into Runwire unless a small generic child-exit classification is genuinely required.

---

### 27.7 Lifecycle integration

Earlier sections of this Omnibus plan proposed adding `WorkerLifecycle` polling to `WorkerPool` and replacing Foundation's SIGALRM wrapper.

With Runwire, keep the desired behavior but move the **generic ticking/wakeup/supervision mechanism** lower.

Target:

```text
Foundation lifecycle/control
        ↓
Omnibus WorkerPool lifecycle adapter
        ↓
Runwire generic supervisor lifecycle/tick
        ↓
process stop/reload
```

Omnibus `WorkerLifecycle` can remain its queue-facing contract where useful:

```php
heartbeat(): void;
stopRequested(): bool;
```

But Omnibus should adapt it to Runwire's generic worker-group lifecycle instead of implementing a separate polling + signal engine.

Foundation should no longer need `SIGALRM` around `WorkerPool::run()`.

---

### 27.8 Signal/wait hardening moves to Runwire

This plan's detailed requirements for:

- minimal signal handlers;
- restoring handlers;
- EINTR/ECHILD handling;
- bounded WNOHANG polling;
- graceful SIGTERM → SIGKILL;
- child signal normalization;
- zombie prevention;

remain required for the **overall behavior**, but their generic implementation and primary test matrix move to Runwire.

Omnibus tests should verify the queue-facing result:

- pool stops cooperatively;
- clean recycle is replaced;
- crash is restarted within budget;
- restart exhaustion surfaces correctly;
- shutdown does not leave queue workers alive;
- lifecycle callbacks are honored;
- Foundation integration does not need a second signal watchdog.

Runwire owns exhaustive raw signal/wait/reap tests.

---

### 27.9 Post-fork resource rule remains mandatory

Runwire delegation does not change the existing Omnibus resource-lifetime rule.

The worker factory must still create process-bound resources **inside the child after fork**:

```text
Runwire master/resource-clean parent
        ↓
fork
        ↓
Runwire child normalization
        ↓
Omnibus worker factory
        ↓
child DBLayer/CacheLayer/Redis/broker resources
        ↓
Omnibus Worker
```

Do not construct and inherit:

- DBLayer/PDO connections;
- Redis/Valkey sockets;
- broker clients;
- CacheLayer network clients;
- outbound HTTP pools;
- mutable lock handles.

Listeners intentionally inherited by Runwire web workers are unrelated to Omnibus queue worker resource ownership.

---

### 27.10 DBLayer semantics remain unchanged

Do not use the Runwire refactor as a reason to change Omnibus's exact DBLayer `Connection` semantics.

For a DB-backed queue worker:

- Foundation/host creates the DBLayer Connection after fork;
- Omnibus durable transport/store owns that exact child-local connection where required;
- `AfterCommitDispatcher` remains tied to the transaction-owning connection;
- atomic workflow/settlement same-connection rules remain authoritative.

Runwire does not create or resolve DB connections.

---

### 27.11 No Runwire networking leakage into Omnibus messaging

Runwire also owns TCP/server/event-loop mechanics, but Omnibus should not become coupled to them merely because it uses Runwire process supervision.

Do not redesign Omnibus transports around Runwire sockets unless a future transport has a concrete, independently justified need.

Omnibus existing responsibilities for Redis/DB/broker/SQS/AMQP abstractions remain unchanged.

Runwire is used here for **generic process supervision**, not as the Omnibus message transport framework.

---

### 27.12 Process execution remains outside Omnibus

Runwire's structured ProcessRunner does not make arbitrary process execution an Omnibus concern.

Do not add:

- `ExecuteCommandMessage` built into Omnibus;
- shell-string execution;
- executable allowlists inside Omnibus;
- UID/GID sandbox policy;
- uploaded script execution.

An application handler may deliberately call a Foundation-authorized Runwire operation, but Omnibus only transports/invokes the handler.

Example:

```text
Omnibus message
  -> application ThumbnailHandler
       -> Foundation operation policy
            -> Runwire structured command
```

---

### 27.13 Foundation migration revision

After Runwire-aligned Omnibus 2.6 is released, Foundation Point 26.8 should:

- require Omnibus 2.6;
- require Runwire 1.0 through Foundation's own native-runtime dependency;
- remove Foundation `WorkerManager::watchPool()` / SIGALRM pool watchdog behavior;
- supply Foundation lifecycle/control policy through the Omnibus/Runwire adapter path;
- retain Foundation parent-clean assertions;
- boot Foundation worker application inside child;
- create DB/cache/broker resources in child;
- retain fresh `foundation.worker` execution per Omnibus message.

Foundation should not call raw `pcntl_*` or `posix_kill()` to supervise an Omnibus pool after migration.

---

### 27.14 Omnibus tests to add/update

Add integration/contract coverage for:

- `WorkerPool` delegates process startup/supervision to Runwire;
- expected concurrency/slots still work;
- clean worker exit/recycle is replaced without crash-budget consumption;
- abnormal child failure consumes crash budget;
- restart exhaustion propagates stable Omnibus-facing failure;
- lifecycle heartbeat/stop checks work without Omnibus-owned SIGALRM;
- parent stop drains children;
- graceful deadline/forced termination behavior is visible correctly through facade;
- child factory runs after fork/normalization;
- process-bound DB/Redis/broker fixture proves child creation;
- no direct inherited parent connection use;
- Foundation-style external lifecycle adapter works;
- Omnibus can run non-pool/single-process consumer paths without Foundation;
- Runwire absence is a Composer/install-time dependency condition rather than a runtime fallback to duplicated `pcntl` logic when WorkerPool is part of normal package.

Raw EINTR/ECHILD/signal restoration/PID bookkeeping exhaustive tests belong primarily in Runwire.

---

### 27.15 Benchmark revision

Keep Omnibus queue benchmarks separate from Runwire process benchmarks.

Measure:

```text
single Omnibus Worker (no process pool)
Omnibus WorkerPool via Runwire, concurrency 1
Omnibus WorkerPool via Runwire, concurrency N
current pre-migration WorkerPool baseline while developing
```

Record:

- parent supervision CPU;
- worker startup time;
- queue throughput;
- message latency;
- RSS;
- restart/recycle cost;
- idle pool CPU;
- shutdown latency.

Runwire raw process supervision benchmarks belong in Runwire.

No benchmark-only bypass of Omnibus Consumer/settlement behavior.

---

### 27.16 Documentation revision

Update Omnibus 2.6 docs to state:

- Runwire is the low-level process runtime used by WorkerPool;
- Omnibus WorkerPool remains queue-facing API;
- Runwire does not own queue/message semantics;
- process-bound resources must be created after fork;
- a queue worker is trusted deployed application code, not a sandbox;
- handlers needing external processes should use an application/Runwire process service rather than shell helpers in Omnibus;
- Foundation supplies app lifecycle/release policy above Omnibus/Runwire.

Replace provisional `ProcessGuard` / “future process runtime” terminology in the final documentation.

---

### 27.17 Revised implementation order

For process-related parts of Omnibus 2.6, use:

```text
1. Release/stabilize Runwire supervisor contract needed by Omnibus.
2. Add Runwire ^1.0 integration/dependency.
3. Adapt Omnibus WorkerPool to Runwire worker group/supervisor.
4. Preserve WorkerLifecycle and queue-facing options through adapter/facade.
5. Remove duplicate direct pcntl/posix supervisor implementation.
6. Re-run post-fork resource and DBLayer integration suites.
7. Add clean-recycle/crash/restart/stop contract tests.
8. Update Foundation integration to remove SIGALRM watchdog.
9. Benchmark queue-facing overhead.
10. Release Omnibus 2.6 before Foundation 3 final acceptance.
```

Do not block non-process Omnibus work on Runwire internals that are irrelevant to WorkerPool.

---

### 27.18 Revised process completion gate

The process/supervision part of Omnibus 2.6 closes only when:

- [ ] released Runwire 1.0 provides the required generic supervisor semantics;
- [ ] Omnibus `WorkerPool` retains a clean queue-facing API;
- [ ] queue/message `Worker` semantics remain Omnibus-owned;
- [ ] raw generic fork/signal/wait/reap/restart implementation is delegated to Runwire rather than duplicated;
- [ ] no alternate direct-`pcntl` fallback silently recreates a second supervisor;
- [ ] clean worker recycle and abnormal crash remain correctly distinguished;
- [ ] post-fork DB/cache/broker ownership tests pass;
- [ ] DBLayer exact-connection/atomic workflow semantics remain intact;
- [ ] Foundation can remove its SIGALRM watchdog;
- [ ] Runwire does not gain Omnibus queue/retry/workflow knowledge;
- [ ] Omnibus queue performance remains acceptable with measured attribution;
- [ ] PHP 8.4/8.5 QA is green.

---

### 27.19 Parent-plan interpretation

Where `omnibus-2.6-foundation-integration-hardening-plan.md` says:

```text
future ProcessGuard/process-runtime library
```

read:

```text
Runwire 1.0
```

Where earlier sections of this plan proposes improving Omnibus's internal raw `pcntl` supervisor, interpret those behaviors as **required end-state semantics**, but implement generic OS-process mechanics in Runwire and test Omnibus's facade/integration behavior above it.

All queue/persistence/CacheLayer/DBLayer portions of earlier sections of this plan remain unchanged.

# Omnibus 2.6 — Foundation Integration, WorkerPool Lifecycle & Runtime Hardening Plan

## Status

Target release: **Omnibus 2.6**

Baseline:

- PHP: `^8.4`
- UID: `^5.0`
- CacheLayer reference integration: raise to `^3.4`
- DBLayer reference integration: raise to `^5.1`
- Unix runtime floor: mandatory `ext-pcntl` + `ext-posix`
- Runwire process-supervision integration: optional alternative backend, tested against `^1.0`
- PHPForge: keep `dev-main@dev`
- Foundation integration target: `infocyph/foundation` Point **26.8**

Runwire **1.0 is released**, but it is **not** a mandatory runtime dependency and
does **not** replace Omnibus's native Unix worker-pool capability. Omnibus 2.6
has a mandatory Unix process-extension floor through `ext-pcntl` +
`ext-posix` and supports these deployment shapes:

1. ordinary FPM/request, direct CLI, Consumer and single-process Worker usage
   with the mandatory extensions installed but without constructing a pool;
2. the built-in Unix `WorkerPool` using `ext-pcntl` + `ext-posix` without
   Runwire;
3. an explicitly selected optional Runwire-backed `WorkerPool` integration.

Earlier raw `pcntl`/`posix` sections are direct requirements for the native
backend. Section 27 defines optional Runwire parity and must not be interpreted
as deleting the native backend.

This release is an additive hardening and ownership-alignment pass. Omnibus remains a framework-agnostic messaging/event/queue library. Foundation must consume Omnibus-owned generic messaging/runtime mechanics rather than maintaining Foundation-only substitutes.

## Plan tracker

| Batch | Scope | Status | Completion evidence |
|---|---|---|---|
| 0 | Plan reconciliation + whole-codebase audit | **COMPLETE** | Current branch/codebase reviewed; FPM/native-PCNTL/optional-Runwire ownership corrected; durable/policy hardening gaps added. |
| 1 | Dependency and capability alignment | **COMPLETE** | CacheLayer `^3.4`, DBLayer `^5.1`, optional Runwire `^1.0` aligned; `ext-pcntl` + `ext-posix` are mandatory runtime requirements and are absent from `require-dev`/`suggest`; full PHP 8.4/8.5 validation is green in run `35680858522`. |
| 2 | Native `WorkerPool` extraction + PCNTL/POSIX hardening | **COMPLETE** | Crash restarts now use per-slot monotonic deadlines; parent lifecycle polling, stop/drain and child reaping continue during backoff. New regression tests pass on PHP 8.4/8.5 (§29.1, §29.4). |
| 3 | Optional Runwire backend + lifecycle parity | **COMPLETE** | Explicit Runwire integration retained. Shared tests prove lifecycle stop/exception behavior during backoff, restart cancellation, escalation and crash-budget exhaustion on both backends (§29.1). |
| 4 | Durable transport/store + coordination integrity | **COMPLETE** | Failure insertion now establishes row ownership before the locked generation comparison; updates preserve newer payload/version and retry claims. Deterministic two-connection regressions pass on all five durable drivers (§29.2). Earlier Batch 4 hardening remains covered by the full suite. |
| 5 | Worker/WorkerPool + integration test matrix | **COMPLETE** | PHP 8.4.16 and 8.5.10 release guards each pass 221 tests / 1,220 assertions with MySQL, MariaDB, PostgreSQL, SQL Server, SQLite, Redis, Valkey and Memcached. Replica writer-affinity checks also pass (§29.4). |
| 6 | Benchmarks, soak and documentation | **COMPLETE** | Upgrade and operations docs now require coordinated durable-storage cutover and explain rollback restrictions (§29.3). Component and native/Runwire pool benchmarks pass on PHP 8.4/8.5 (§29.4). |
| 7 | Omnibus 2.6 release gate | **AWAITING CANDIDATE CI** | All three review findings are resolved and local release guards pass on PHP 8.4/8.5. Run and record fresh lowest/stable CI on the committed corrected candidate before publication; previous run 35682932960 predates these fixes (§29.4). |
| 8 | Foundation Point 26.8 post-release migration | **POST-RELEASE** | Not an Omnibus 2.6 release blocker. After publication, Foundation raises Omnibus to `^2.6`, removes `WorkerManager::watchPool()`, keeps parent-clean/app policy, and can use the native pool without Runwire or explicitly opt into Runwire. |

**Current execution status:** **All three release-readiness findings are resolved. Batches 2–6 are complete with local PHP 8.4/8.5 release-guard evidence. Batch 7 awaits fresh lowest/stable CI on the committed corrected candidate before publication. Batch 8 remains post-release.**

**Mandatory PCNTL/POSIX runtime-floor audit:** Batches **1–6** were
re-audited after promoting `ext-pcntl` and `ext-posix` to mandatory runtime
requirements. Dead extension/function capability probes and fallback branches
were removed from Worker/native/Runwire supervision, WorkerPool and SQLite
integration tests, DB contention and WorkerPool benchmarks; native PCNTL signal
and wait-error constants are now used directly. Cross-cutting source, transport,
integration, workflow, test and documentation sweeps found no remaining stale
PCNTL/POSIX optional-runtime branches. Full PHP 8.4/8.5 QA, analysis, clean
install, replica-affinity and benchmark validation is green in run
`35681862645`.

**Previous release-gate verification:** the reviewed candidate was a clean descendant of
tag `2.5` with no stray generated/vendor/lock artifacts. Published/tagged
dependency floors used by the 2.6 graph were rechecked at the gate: UID
`5.0`, CacheLayer `3.4`, DBLayer `5.1`, and Runwire `1.0`. The final
documentation-floor cleanup and complete PHP 8.4/8.5 validation are green in
run `35682932960`. That run tested `603edd83eed3b2e7f25caa42d70b5f2945bf56af`;
the reviewed HEAD `40ed1697ad591aef5c4ee1b8f7f1a0265439a461` adds only the
plan-status update. The later review in §29 reopens the gate despite that green run.

Tracker rule: mark a batch **COMPLETE** only after its code, focused tests and
relevant QA/benchmark evidence are green. Do not advance tracker state from code
presence alone.

---

## 1. Goals

1. Align Omnibus's optional CacheLayer and DBLayer integration suites with the versions currently consumed by Foundation.
2. Make `WorkerPool` lifecycle-aware so host runtimes can supply heartbeat and cooperative stop checks without wrapping the pool in their own signal watchdog.
3. Retain and harden the native `pcntl`/`posix` WorkerPool backend, while providing Runwire 1.0 as an explicitly selected optional backend behind the same queue-worker facade.
4. Preserve the correct post-fork ownership model for DB connections, Redis clients, broker connections, CacheLayer resources, sockets, locks, and other process-bound state.
5. Keep durable DBLayer-backed Omnibus services bound to the exact `Connection` instance required for queue/workflow transaction semantics.
6. Remove generic Omnibus supervision mechanics from Foundation where they currently exist only because Omnibus lacks the corresponding API.
7. Keep one queue-worker policy surface while supporting two process backends: native `pcntl`/`posix` by default and optional Runwire when explicitly selected; do not duplicate queue/retry/recycle policy between them.
8. Keep ordinary FPM/request, direct dispatch, Consumer and single-process Worker usage independent of Runwire and pool construction, while accepting the mandatory PCNTL/POSIX package floor.
9. Add focused tests and benchmarks proving the worker lifecycle and persistent-process behavior remain deterministic, bounded, leak-free, and low-overhead.

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

### 3.3 Runwire

Runwire 1.0 is an **optional alternative WorkerPool backend**, not a prerequisite
for Omnibus or for the built-in Unix pool.

Keep Runwire out of mandatory production requirements. Use it as a
development/reference integration dependency and advertise it through Composer
`suggest`. Place `ext-pcntl` and `ext-posix` in global `require`; do not
duplicate them in `require-dev` or `suggest`.

Backend selection must be explicit. Do not silently switch supervision
semantics merely because another package installs Runwire.

When Runwire is unavailable:

- direct `MessageBus`, `Consumer`, workflows, transports, FPM/request usage
  and single-process `Worker` continue to work;
- native `WorkerPool` continues to work through the mandatory
  `ext-pcntl` + `ext-posix` runtime requirements;
- no Runwire class may be referenced during ordinary bootstrap.

When the native process extensions are unavailable, Composer must reject the
Omnibus 2.6 installation because they are mandatory runtime requirements.

### 3.4 Runtime capability matrix

| Runtime/use | Runwire | PCNTL/POSIX | Expected support |
|---|---:|---:|---|
| FPM/request-driven dispatch | not required | mandatory package floor | full non-pool Omnibus |
| normal CLI direct dispatch/Consumer | not required | mandatory package floor | full non-pool Omnibus |
| single-process `Worker` without signal handling | not required | mandatory package floor | supported |
| single-process `Worker` with Unix signals | not required | mandatory package floor | supported |
| native `WorkerPool` | not required | mandatory package floor | supported/default Unix pool backend |
| Runwire-backed `WorkerPool` | required | mandatory package floor plus Runwire capabilities | supported when explicitly selected |

Do not encourage creating a process pool from inside an FPM request. FPM is a
valid host for request-time Omnibus; `WorkerPool` is a CLI/persistent-process
capability.

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

## 16. Runwire 1.0 process-runtime boundary

Runwire 1.0 is a released optional process runtime that Omnibus 2.6 may use as
an alternative WorkerPool backend.

Omnibus retains its native `pcntl`/`posix` backend. The queue-facing
`WorkerPool` facade, message-worker policy, lifecycle semantics and outcome
classification must be shared; backend-specific OS mechanics should be isolated
so policy does not fork into two independent implementations.

### Runwire owns

- fork/spawn and child PID tracking;
- signal registration/restoration and child normalization;
- wait/reap handling, including EINTR/ECHILD correctness;
- graceful/forced process termination;
- generic restart/backoff bookkeeping;
- monotonic supervisor timers;
- worker-group lifecycle/status primitives.

### Omnibus remains owner of

- queue worker concurrency/options;
- worker factory semantics;
- mapping Omnibus clean recycle vs requested stop vs crash;
- message-consumer lifecycle;
- queue retry/failure/settlement behavior;
- queue/workflow integration;
- the public `WorkerPool` API.

Supported dependency directions:

```text
Foundation
    ↓
Omnibus WorkerPool
    ├─ native PCNTL/POSIX backend ──→ OS
    └─ optional Runwire backend ──→ Runwire 1.0 ──→ OS
```

Runwire must never depend on Omnibus, and Omnibus must never depend on
Foundation.

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

Runwire owns exhaustive raw process-supervision tests: signal restoration,
EINTR/ECHILD handling, PID bookkeeping, wait/reap internals and low-level forced
termination. Omnibus tests the queue-facing contract above Runwire and keeps only
integration-level assertions needed to prove the facade preserves Omnibus
semantics.

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
6. Runwire 1.0 as the WorkerPool process runtime and its underlying Unix extension requirements;
7. process isolation is not security sandboxing;
8. Foundation/Omnibus/Runwire ownership boundaries.

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

- Composer/test metadata targets CacheLayer `^3.4` and DBLayer `^5.1`;
  Runwire `^1.0` remains optional, while PCNTL and POSIX are mandatory
  runtime requirements.
- Ordinary FPM/request, direct dispatch, Consumer and single-process Worker
  paths run without Runwire or pool construction, while the package retains
  mandatory PCNTL/POSIX requirements.
- Native `WorkerPool` remains operational without Runwire on the mandatory
  PCNTL/POSIX Unix runtime floor.
- Optional Runwire WorkerPool backend can be selected explicitly and preserves
  the same queue-facing lifecycle/recycle/stop contract.
- Backend selection is deterministic and cannot change merely because Runwire
  appears transitively.
- Native wait/reap errors cannot create an infinite/busy supervision loop.
- Parent signal state is restored; child state is normalized; all children are
  reaped on success and every failure/shutdown path.
- Clean recycle does not consume crash restart budget.
- Pool lifecycle heartbeat/stop works without Foundation SIGALRM.
- DBLayer 5.1 durable queue/workflow/failure/after-commit tests pass.
- DB payload storage safely round-trips serializer bytes supported by the
  documented DB integration contract.
- CacheLayer 3.4 coordination tests pass and cleanup errors cannot mask the
  primary handler/send failure.
- Redis/Valkey structural corruption is detected deterministically rather than
  converted into malformed reservations.
- Exact DBLayer connection semantics remain intact.
- Process-bound resources are child-created and documented/tested.
- Omnibus exposes no arbitrary shell/process execution API.
- Foundation can delete its pool-specific signal watchdog without being forced
  to install Runwire.
- Native/Runwire worker benchmarks show no unacceptable regression.

---

## 25. Recommended implementation order

1. Align CacheLayer 3.4, DBLayer 5.1, optional Runwire 1.0 and mandatory
   PCNTL/POSIX runtime dependency metadata/docs.
2. Extract/harden native WorkerPool mechanics without removing native support.
3. Centralize WorkerPool outcome/lifecycle/restart policy above backend-specific
   supervision.
4. Add explicit optional Runwire backend and parity tests.
5. Harden DBLayer payload storage, row hydration, claim exactness and
   failure-state retry semantics.
6. Harden CacheLayer cleanup/error precedence and Redis structural invariants.
7. Close envelope redispatch/transient-stamp and external PSR-provider edge
   cases.
8. Run the full native/Runwire/core-without-pool-construction test matrix.
9. Add benchmarks/soaks and update operations/performance/upgrading/security
   documentation.
10. Release Omnibus 2.6.
11. Upgrade Foundation to `^2.6`, remove `WorkerManager::watchPool()` and
    preserve Foundation-specific parent-clean/runtime-control policy.

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
Runwire 1.0
 ├─ worker-group supervision
 ├─ fork/signal/wait/reap
 ├─ restart/backoff
 └─ graceful/forced process termination
        │
        ▼
pcntl / posix / OS
```

The important boundary is:

> Foundation owns application/runtime policy; Omnibus owns messaging and queue-worker policy; Runwire 1.0 owns reusable low-level OS process mechanics.

That keeps each library focused, removes duplicate supervision logic from Foundation, and avoids turning Omnibus into a generic process-execution or security-sandbox package.

---

---

## 27. Native + optional Runwire WorkerPool architecture

### 27.1 Ownership

Omnibus owns the queue-facing `WorkerPool` API, worker factory contract,
message-worker outcome classification, lifecycle integration, restart intent
and public failure semantics.

The native backend owns Omnibus's built-in Unix mechanics:

- `pcntl_fork()`;
- signal registration/restoration;
- wait/reap and EINTR/ECHILD handling;
- child normalization;
- PID tracking;
- graceful/forced termination.

The optional Runwire backend adapts the same Omnibus policy to Runwire 1.0
public supervisor primitives.

Foundation owns application/runtime policy, configuration, parent-clean
assertions, release-generation controls and child application boot.

### 27.2 Backend selection

Native PCNTL/POSIX remains the default WorkerPool backend for compatibility and
standalone operation. Runwire is opt-in.

Do not auto-select Runwire from `class_exists()` or transitive installation.
The exact public selection shape may be refined during implementation, but it
must be explicit and must not expose Runwire types in ordinary Omnibus APIs.

### 27.3 Shared behavioral contract

Both backends must prove:

- stop-before-run is a no-op run with no children;
- repeated `requestStop()` is idempotent;
- worker factory executes only after child creation/normalization;
- lifecycle heartbeat/stop polling is bounded and does not busy-spin;
- lifecycle failure drains children before escaping;
- clean max-message/runtime/memory/growth completion is planned recycle;
- requested stop is not recycle;
- unexpected failure consumes crash budget;
- restart exhaustion is bounded and surfaces a stable Omnibus failure;
- graceful stop escalates after the configured deadline;
- every child is reaped;
- parent signal state is restored;
- process-bound resources are child-created.

### 27.4 Native backend requirements

Implement Sections 6–12 directly for the native backend.

In particular:

- replace indefinite blocking supervision where required for lifecycle polling;
- distinguish `pid > 0`, `pid === 0`, EINTR, ECHILD and unexpected wait errors;
- move full shutdown work out of asynchronous signal callbacks;
- use monotonic deadlines;
- treat disappearing children during signalling as a reconciliation event;
- reset only Omnibus-owned inherited child process state;
- preserve bounded polling/CPU behavior.

### 27.5 Runwire backend requirements

Runwire remains optional:

```json
"require-dev": {
    "infocyph/runwire": "^1.0"
}
```

Composer `suggest` should describe it as an optional alternative WorkerPool
backend.

When selected:

- use released Runwire public APIs only;
- adapt parent lifecycle polling to Runwire timers/loop;
- advertise ready only after child resources/worker graph are built;
- map planned Omnibus recycle to Runwire's planned-recycle primitive;
- do not install competing child SIGTERM/SIGINT handling over Runwire;
- allow supervisor drain to complete before rethrowing lifecycle failure.

Runwire never gains Omnibus queue/retry/workflow knowledge.

### 27.6 Foundation migration

Foundation 26.8 should not require Runwire solely to use Omnibus WorkerPool.
It may:

- use native WorkerPool on PCNTL/POSIX;
- explicitly choose Runwire where broader Runwire integration is desired;
- keep FPM/request paths independent of either pool backend.

In both cases Foundation removes its duplicate `WorkerManager::watchPool()` /
SIGALRM watchdog and supplies lifecycle policy through Omnibus.

### 27.7 Process completion gate

- [X] Native PCNTL/POSIX WorkerPool requirement retained and promoted to the
  mandatory package runtime floor.
- [X] Runwire remains optional.
- [X] Core/FPM paths are proven independent of Runwire and pool construction.
- [X] Native backend hardening complete, including nonblocking crash backoff (§29.1).
- [X] Explicit Runwire backend complete.
- [X] Common behavioral suite covers crash-backoff lifecycle parity on both backends (§29.1).
- [X] Fork-safety/resource ownership tests green.
- [ ] Foundation migration works with native backend and optional Runwire (Batch 8, post-release; not an Omnibus release blocker).
- [X] Corrected working tree passes local PHP 8.4/8.5 release guards and benchmarks (§29.4).
- [ ] Fresh lowest/stable CI recorded for the committed corrected candidate before publication.

---

## 28. Whole-codebase review additions (2026-09-21)

### 28.1 Binary-safe durable payload contract

`EnvelopeSerializer` is byte-string based and `CallbackEnvelopeSerializer`
supports binary codecs such as MessagePack, while DBLayer queue/workflow/failure
schemas currently use text-oriented payload columns.

Resolve this contract mismatch:

- define portable storage for arbitrary serializer bytes;
- cover queue payloads, workflow-item payloads and raw failure payloads;
- poison binary payloads must remain persistable;
- preserve serializer byte limits before any storage encoding expansion;
- benchmark storage/CPU overhead;
- do not claim binary DBLayer compatibility until MySQL/MariaDB/PostgreSQL/SQL
  Server/SQLite round-trip tests prove it.

Prefer a transparent portable storage encoding if it keeps existing schemas and
measured overhead is acceptable; otherwise document/implement driver-correct
binary types and migration.

### 28.2 CacheLayer cleanup and exception precedence

Harden cleanup after application execution:

- `UniqueSender`: lease-release failure must not mask the original send failure;
- `OverlapProtectionScope`: release failure must not mask handler or
  post-execution lease-loss failure;
- `CircuitBreakerScope::withLock()`: lock-release failure must not mask the
  primary state/handler failure;
- post-success coordination cleanup/state-reset failures must be observable.

Distinguish pre-execution rejection from post-execution coordination uncertainty
so automatic retries do not blindly repeat already-successful side effects.

### 28.3 DBLayer hydration and ownership exactness

- make `DBLayerFailureStore` integer/boolean hydration strict rather than
  coercing arbitrary strings;
- after queue reservation selection, assert the ownership update changed exactly
  the selected row count;
- retain receipt/token guards;
- add malformed-row and affected-row mismatch tests.

### 28.4 Failure-store re-failure/retry-state semantics

Define same-message-ID behavior when a newer terminal failure arrives while an
older row is `retrying` or `sent`, especially after
`FailureRemovalAfterRetryFailed`.

Test:

- retry accepted → removal fails → same message fails again;
- active retry claim races with a new terminal failure;
- older failure writes cannot overwrite newer attempt/time state;
- DBLayer and in-memory stores remain semantically equivalent.

Prefer a bounded generation/attempt rule instead of blindly resetting claim
ownership.

### 28.5 Redis/Valkey structural invariant handling

Redis scripts assume ready/reserved IDs have matching payload, attempt,
receipt/message-id hashes.

- missing structural fields must raise a dedicated backend-state failure rather
  than creating an invalid `Reservation`;
- decide whether orphan IDs are atomically quarantined/removed or retained for
  operator repair;
- malformed serialized payload remains normal poison-message behavior when the
  structure itself is intact;
- test Redis and Valkey.

### 28.6 Envelope redispatch and transient stamps

Review redispatch of an existing `Envelope`:

- `HandledStamp` is transient synchronous result metadata and has no core
  durable codec;
- repeated dispatch currently appends routing metadata.

Define a bounded contract so transient stamps cannot accidentally enter durable
serialization and repeated dispatch cannot grow route metadata indefinitely,
while preserving explicit delay/message identity semantics.

### 28.7 External PSR-14 provider hardening

Validate arbitrary third-party `ListenerProviderInterface` output before array
index access. Malformed listener values should fail through the documented
exception path without notices/warnings.

### 28.8 Core/FPM smoke gate

Prove pool construction and Runwire remain optional while the Unix process
extension floor is mandatory:

- mandatory Composer requirements include PCNTL/POSIX but no Runwire;
- ordinary dispatch/serialization/transports/request integrations load without
  Runwire or constructing a pool backend;
- `Worker(handleSignals: false)` avoids signal registration while mandatory
  PCNTL/POSIX remain installed;
- native WorkerPool uses the guaranteed PCNTL/POSIX runtime directly rather
  than re-probing extension availability;
- documentation clearly separates FPM/request usage from CLI/persistent pool
  usage.

### 28.9 Priority

Sections 28.1–28.5 are Batch 4 correctness/reliability gates.
Sections 28.6–28.8 are Batch 4/5 hardening gates unless implementation evidence
justifies an explicitly documented deferral.

---

## 29. Release-readiness review (2026-09-22)

The review initially reopened Batches 2–7. The fixes and new verification below
close Batches 2–6; Batch 7 still requires fresh candidate CI. Historical review
evidence is retained separately from the corrected-working-tree results.

### 29.1 Native crash backoff blocks lifecycle polling

**Status: RESOLVED. Owners: Batches 2, 3 and 5.**

Before the fix, `NativeWorkerPoolBackend::restartCrashedWorker()` slept inside the parent
supervision loop. During that sleep, lifecycle callbacks and child reaping stopped.
A reproduction with a 10 ms lifecycle interval, a 1 second crash backoff and a
stop request after 150 ms completed in 1.023 seconds on the native backend,
versus 0.154 seconds on Runwire. The native heartbeat gap reached 1.002 seconds.
These are diagnostic measurements, not a stable performance baseline.

Resolution: pending restarts are tracked per slot with monotonic deadlines. The
supervisor remains active even when all children have exited but restarts are
pending. Stop and exceptional shutdown cancel pending restarts. Ten added
backend-contract cases cover lifecycle stop/exception handling with one or two
slots, a child ignoring SIGTERM, cancellation without respawn, no remaining
children, and scheduled crash-budget exhaustion. The original native code fails
the four new native lifecycle cases; the corrected code passes them.

Acceptance criteria:

- schedule per-slot restarts with monotonic deadlines instead of sleeping inside
  crash handling;
- continue lifecycle polling, child reaping and shutdown escalation while a
  restart is pending;
- cancel pending restarts when stop is requested, without spawning another child;
- preserve crash-budget and clean-recycle semantics;
- add bounded regression tests on both backends for heartbeat/stop during crash
  backoff, including other live children and no remaining child processes.

### 29.2 Concurrent first failure writes bypass generation ordering

**Status: RESOLVED; original defect reproduced on PostgreSQL. Owners: Batches 4 and 5.**

Before the fix, `DBLayerFailureStore::add()` first read the existing version with a locking
select and then performed an unconditional upsert. On PostgreSQL, two transactions
can both find no row for a previously absent ID. An older writer can subsequently
update the newer insertion, replacing its payload/version and resetting retry
state. Locking existing rows does not close this absent-row race. PostgreSQL's
[transaction isolation documentation](https://www.postgresql.org/docs/17/transaction-iso.html)
describes the concurrent `ON CONFLICT DO UPDATE` behavior underpinning this finding.

Resolution: within the existing exact-connection transaction, an upsert inserts
the failure or updates only its identical ID, establishing row ownership without
replacing existing failure/retry state. The locked version read then decides
whether to update the payload and reset retry state. Twenty deterministic
two-connection cases cover both write orders, attempt/time ordering and retry
claim preservation/invalidation across SQLite, MySQL, MariaDB, PostgreSQL and
SQL Server. Against the original implementation, the PostgreSQL stale-writer
cases fail; the corrected implementation passes all twenty cases on both PHP
versions.

Acceptance criteria:

- make version comparison atomic with persistence, including the absent-row case;
- preserve newer attempt/time state and retry ownership against stale writes;
- add a deterministic two-connection regression for concurrent insertion of the
  same absent ID with different generations, covering both write orders;
- verify the implementation on PostgreSQL and the other supported durable drivers;
- retain DBLayer/in-memory generation semantics and exact connection ownership.

### 29.3 DB payload rollout and rollback restrictions

**Status: RESOLVED. Owner: Batch 6.**

Omnibus 2.6 reads legacy unprefixed payloads but always writes the new versioned
wrapper. Omnibus 2.5 passes stored payloads directly to its serializer, so an old
worker can classify a new queue payload as poison. Workflow and failure-store
readers also lack wrapper support. Backward reading compatibility does not make
mixed-version operation or rollback safe.

Resolution: `docs/upgrading.rst` and `docs/operations.rst` now cover producers,
workers, workflow dispatchers and failure-retry processes, coordinated cutover,
legacy-row compatibility and the need for separately verified conversion or
restore before rollback. Guidance was checked against the 2.5 decoder and the
2.6 queue/workflow/failure storage wrappers.

Acceptance criteria:

- upgrade and operations documentation explicitly prohibit 2.5 readers sharing
  durable storage after 2.6 writers start;
- document a coordinated stop of producers, workers, workflow dispatchers and
  failure-retry processes, followed by upgrading every storage participant before
  resuming writes;
- explain that existing legacy rows can remain for 2.6 readers;
- prohibit direct rollback once wrapped rows exist unless all affected durable
  data is safely converted or restored through a separately verified procedure;
- review the documented cutover against queue, workflow and failure-store paths.

### 29.4 Verification evidence and release closure

Initial review evidence for HEAD `40ed1697ad591aef5c4ee1b8f7f1a0265439a461`:

- [CI run 35682932960](https://github.com/infocyph/Omnibus/actions/runs/35682932960)
  passed PHP 8.4/8.5 lowest/stable QA, analysis, clean production installation,
  replica writer affinity and benchmarks on the preceding code-equivalent commit;
- local PHP 8.5.4 detailed checks: 178 tests passed, 13 integration tests failed
  because required database services or Redis/Memcached extensions were missing;
- formatting, PHPStan, Psalm, architecture and Rector checks passed;
- component and WorkerPool benchmarks completed; no stable-environment regression
  comparison was established by this local run;
- Composer validation and stable runtime constraints passed;
- the initial release guard stopped at sandbox DNS restrictions; a subsequent
  network-enabled audit completed with zero advisories and one non-blocking
  abandoned development dependency, `doctrine/annotations`;
- Runwire IPC required execution outside the restricted sandbox.

Corrected working-tree verification (2026-09-22):

- `composer ic:process` completed; no detector thresholds or exclusions changed.
- `composer ic:tests:details` completed analysis; its initial four cache failures
  were traced to missing test-service passwords and corrected in the local test
  environment. No application change or test bypass was needed.
- `composer ic:release:guard` passed independently on PHP **8.4.16** and
  **8.5.10**, each with **221 tests / 1,220 assertions**, including all durable
  database and cache integrations, formatting, reference/duplicate/comment
  detectors, architecture, PHPStan, Psalm and Rector checks.
- Both security audits report zero advisories; `doctrine/annotations` remains
  the existing non-blocking abandoned development dependency.
- Three deliberate-stale-replica writer-affinity cases passed on MySQL,
  MariaDB and PostgreSQL (6 assertions).
- A fresh production-only install from the current lock file passed platform
  checks and a single Worker smoke test with Runwire absent on PHP 8.5.
- `composer benchmark` completed on both PHP versions; native and Runwire
  recycle measurements each reported zero parent memory growth. These local
  measurements are not a stable-environment throughput regression guarantee.
- PHP 8.5 soak checks passed: 100 recycle cycles per backend with zero parent
  memory growth; 10,000 durable messages drained with no duplicate delivery;
  100 workflows / 10,000 items with zero reconciliation errors, duplicate
  handler executions or terminal regressions.
- Temporary integration-service containers and their disposable volumes were
  removed after validation.
- The new tests were checked against temporary copies of the original classes:
  the original native backoff fails four lifecycle cases, and the original
  PostgreSQL failure store fails two stale-writer generation cases.

The three findings are resolved. Batches 2–6 are complete. Before closing Batch 7,
commit the corrected candidate and run the complete PHP 8.4/8.5 lowest/stable CI
matrix; record its tested commit and run URL. Local guards used the installed
lock-file dependency set and do not substitute for fresh lowest/stable
resolution. No new remote CI run or release has been triggered by this work.
Batch 8 remains post-release.

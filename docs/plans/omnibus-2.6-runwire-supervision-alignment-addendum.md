# Omnibus 2.6 — Runwire 1.0 Supervision Alignment Addendum

## Status

Target: **Omnibus 2.6 + Runwire 1.0 + Foundation 3**

Parent plan: `docs/plans/omnibus-2.6-foundation-integration-hardening-plan.md`

Runwire plan: `infocyph/Runwire` → `docs/plans/runwire-1.0-foundation-3-launch-plan.md`

This addendum **supersedes the parent plan's provisional references to a future `ProcessGuard` / unnamed process-runtime library**. That lower-level library now exists as **Runwire** and is a Foundation 3 launch dependency.

The parent Omnibus plan remains authoritative for CacheLayer 3.4, DBLayer 5.1, message-consumer semantics, persistence, tests and Foundation integration except where this addendum changes process-supervision ownership.

---

# 1. Revised ownership

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

# 2. Current collision to remove

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

# 3. Preserve `WorkerPool` as Omnibus public API/facade

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

# 4. Dependency decision

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

# 5. `Worker` remains Omnibus-owned

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

# 6. Clean exit vs crash semantics

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

# 7. Lifecycle integration

The parent Omnibus plan proposed adding `WorkerLifecycle` polling to `WorkerPool` and replacing Foundation's SIGALRM wrapper.

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

# 8. Signal/wait hardening moves to Runwire

The parent plan's detailed requirements for:

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

# 9. Post-fork resource rule remains mandatory

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

# 10. DBLayer semantics remain unchanged

Do not use the Runwire refactor as a reason to change Omnibus's exact DBLayer `Connection` semantics.

For a DB-backed queue worker:

- Foundation/host creates the DBLayer Connection after fork;
- Omnibus durable transport/store owns that exact child-local connection where required;
- `AfterCommitDispatcher` remains tied to the transaction-owning connection;
- atomic workflow/settlement same-connection rules remain authoritative.

Runwire does not create or resolve DB connections.

---

# 11. No Runwire networking leakage into Omnibus messaging

Runwire also owns TCP/server/event-loop mechanics, but Omnibus should not become coupled to them merely because it uses Runwire process supervision.

Do not redesign Omnibus transports around Runwire sockets unless a future transport has a concrete, independently justified need.

Omnibus existing responsibilities for Redis/DB/broker/SQS/AMQP abstractions remain unchanged.

Runwire is used here for **generic process supervision**, not as the Omnibus message transport framework.

---

# 12. Process execution remains outside Omnibus

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

# 13. Foundation migration revision

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

# 14. Omnibus tests to add/update

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

# 15. Benchmark revision

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

# 16. Documentation revision

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

# 17. Revised implementation order

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

# 18. Revised process completion gate

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

# 19. Parent-plan interpretation

Where `omnibus-2.6-foundation-integration-hardening-plan.md` says:

```text
future ProcessGuard/process-runtime library
```

read:

```text
Runwire 1.0
```

Where the parent plan proposes improving Omnibus's internal raw `pcntl` supervisor, interpret those behaviors as **required end-state semantics**, but implement generic OS-process mechanics in Runwire and test Omnibus's facade/integration behavior above it.

All queue/persistence/CacheLayer/DBLayer portions of the parent plan remain unchanged.
# Omnibus 3.0.0 audit and implementation plan

Date: 2026-10-07 (Asia/Dhaka). Audited production revision: `17a86f28215b36f237a9db3e014c5425c4d6ea0a`, local tag `2.6`.

Target: **3.0.0 directly from 2.6**. The user has selected the next major and the full improvement scope; there is no intermediate patch/minor release in this plan.

Status: **Batches 0–5 complete with recorded exact-SHA CI evidence; Batch 6 next.** The branch is not ready to certify 3.0.0: Batches 6–8 and final matched-environment performance acceptance remain open. O07's two inherited complexity violations were resolved in Batches 1–2 without relaxing PHPForge limits. No release has been tagged or published.

Engineering authority: [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) and [agent workflow](../../vendor/infocyph/phpforge/resources/AGENTS.md), as installed during this audit.

## Committed release scope

Deliver the confirmed fixes, **UID 6 / CacheLayer 4 / DBLayer 6 / Runwire 2.1.1**, passed-instance Runwire composition, process-extension portability, lease/delivery hardening, measured performance improvements, and the documentation/CI/release work below as one **3.0.0** release. Retire the previous dependency-major support policy; no dual-generation compatibility shim is required. Keep PHP `^8.4` and certify PHP 8.4/8.5.

All previously optional improvement areas are now assigned to implementation batches. Each area must finish with a tested improvement or an evidence-backed disposition where changing code would add cost without benefit. Correctness fixes, dependency adoption, Runwire composition, portability, and release gates are mandatory. An unmeasured optimization, redundant abstraction, new broker adapter, or automatic concurrency is not justified merely by the major version.

Preserve existing public parameter names, useful constructors, synchronous return values, and provider ownership unless an item below requires a documented change. Record intentional 3.0 breaks in the upgrade guide and prove the replacement path. Never modify the existing `2.6` tag.

No queue schema or wire-format migration is planned unless a fix or measured storage change requires one. Keep envelope version 1, the 26-character ULID representation, and existing wrapped and unwrapped DB payload readers. Test a coordinated 2.6-to-3.0 cutover and the corresponding data compatibility; preserve the documented restriction on rolling back wrapped durable data to 2.5 readers.

## Audit coverage and current evidence

The audit combined repository-wide PHPForge detectors with risk-based source inspection and adversarial probes. Reviewed boundaries include routing and handler dispatch, PSR-14 events, codecs and payload limits, reservation/retry/failure settlement, native and Runwire pools, workflow transitions, DBLayer SQL and transaction ownership, Redis Lua, broker capability boundaries, CacheLayer policies, deferred dispatch, scheduling, broadcasting, telemetry, documentation, and benchmark/CI configuration. There are 162 production PHP files. This is not a claim of formal verification or absence of every vulnerability.

| Check | Current result and limitation |
| --- | --- |
| `composer validate --strict` | Passed. |
| `composer ic:doctor`, `ic:list-config`, `ic:active-config` | Completed; host lacks `pdo_sqlsrv`. PHP 8.4/8.5 are the configured matrix. |
| Live `composer audit --locked --format=json` | No published advisories; abandoned `doctrine/annotations`, introduced by development tool `phpbench/phpbench 1.7.0`. Abandonment is not itself an advisory. |
| `composer ic:tests:details` outside sandbox | **Failed overall:** PHPStan reports the two O07 violations. Pest passed **192 tests / 1,057 assertions**. Syntax, references, comment policy, duplicate detector, Pint, PHPCS, Deptrac, Psalm security analysis, and Rector dry run passed. |
| Final `composer ic:release:guard` | **Failed:** Composer validation, stable runtime constraints, advisory audit and the 192-test suite passed; PHPStan again failed on the same two O07 complexity violations. |
| Original sandbox quality attempt | Interrupted after stalling at fork/IPC tests; the completed host run above replaces that inconclusive result. |
| Released dependency compatibility | An isolated `/tmp/omnibus-audit-next` install resolved exactly UID 6.0, CacheLayer 4.0, DBLayer 6.0, and Runwire 2.1.1 from Composer; the existing suite passed **192 tests / 1,057 assertions** against that install. Reflected class paths confirmed the probes used the isolated dependencies. No repository dependency files were changed. |
| Focused source probes | O02–O05 reproduced against both dependency generations. O01 reproduced with a real unrelated child process. O06 reproduced on a disposable Redis 8.10 container, subsequently removed. |
| Follow-up CI-discovery probe | With `GITHUB_ACTIONS=true`, the host's missing `pdo_sqlsrv` produced `configuredDatabaseDrivers(['mssql']) = []` without an exception. O08 explains why configured service coverage can silently disappear. |
| `composer benchmark` | Existing component and worker-pool harnesses passed. These are component/supervision measurements, not host-application RPM certification or a before/after comparison. |
| `composer soak:consumer` | 10,000 messages; queue depth 0; reported PHP allocated-memory growth 0. |
| `composer soak:durable` | 10,000 SQLite messages / two alternating consumers; queue depth 0. |
| `composer soak:workflow` | 100 workflows / 10,000 items; 1,020 reconciliation attempts; 0 reconciliation errors, duplicate handler executions, or terminal regressions. |
| Full external matrix | Not run during this audit: MySQL, MariaDB, PostgreSQL, SQL Server, Valkey, Memcached, replica affinity, genuine FPM/other persistent hosts, and final candidate CI. The disposable Redis probe is not the full Redis matrix. |

Local service tests are conditionally registered. The 192-test result is the ordinary host baseline, not evidence that every external backend passed. The short cycle-based soak scripts are not five-minute persistent-runtime certification and do not measure full process-tree RSS.

No published dependency vulnerability or direct unauthenticated RCE/SQL-injection path was demonstrated. The confirmed findings below include availability, coordination, and integrity risks. Storage-corruption probes assume corrupt backend state or a writer that can modify backend metadata; they do not establish that an unauthenticated caller can write it. Preserve codec allow-lists, bounded decoding, prepared SQL, validated identifiers, token-guarded settlement, and tenant-specific resource/key configuration.

## Confirmed findings and required remediation

### O01 — Native pool reaps host-owned children (high)

Owner: `src/Consumer/NativeWorkerPoolBackend.php:165`, `pollChild()`.

`pcntl_waitpid(-1, ..., WNOHANG)` can consume the exit status of any child of the application process. A completed unrelated child was created before running the pool. After pool shutdown, the host's `pcntl_waitpid($externalPid, ...)` returned `-1` / `PCNTL_ECHILD`, proving that the pool had already reaped it.

Change: poll only PIDs in the backend's owned-child registry. Handle `EINTR` and an individual disappeared child without clearing unrelated owned children. Preserve monotonic restart scheduling, shutdown escalation, signal restoration, and no-zombie behavior. Check the explicitly selected Runwire supervisor's documented child-reaping ownership separately; do not assume upgrading that backend solves native ownership.

Acceptance: the host can still reap its unrelated child with the correct status after the pool runs; concurrent owned child exits are reaped; crash exhaustion, clean recycle, pending-restart cancellation, and bounded shutdown regressions remain green.

### O02 — Malformed DB storage wrapper bypasses poison handling (high)

Owners: `src/Integration/DBLayer/DBLayerTransport.php:145`, `StoredPayload`, and failure-store hydration.

`StoredPayload::decode()` runs outside the serializer failure boundary. With one row containing `~omnibus:b64:v1~%%%` and one valid row, `receive(limit: 2)` threw `UnexpectedValueException` after **both rows were reserved**. Neither reservation reached `Consumer`; the malformed row can repeatedly disrupt the batch after visibility expiry. `DBLayerFailureStore::hydrate()` similarly unwraps before its recoverable envelope-decoding boundary.

Change: include storage unwrapping in the poison-payload boundary. Preserve bounded original stored bytes when unwrapping fails, retain stable message identity and receipt, persist failure before rejection, and return the valid reservations in the same batch. Do not silently convert corrupted bytes into a business message. Decide and document how a corrupt stored failure remains inspectable without making `all()` fail for every entry.

Acceptance: malformed base64, empty wrappers, unknown formats, invalid JSON, oversized bytes, and a healthy neighboring message; binary codecs and legacy unprefixed rows remain supported. Failure persistence errors must still prevent destructive rejection. Run on all durable SQL backends.

### O03 — Conflicting workflow stamps select different workflows (high)

Owners: `WorkflowExecutionScope::identity()`, `WorkflowCoordinator::identity()`, DB atomic settlement, and decoded envelope validation.

Execution prioritizes `BatchStamp`; coordinator/DB settlement prioritizes `ChainStamp`. A delivery containing valid stamps from both workflows executed and marked the batch item handled, then settlement checked the still-dispatched chain item and threw `WorkflowInconsistentDelivery`. This reproduced on both dependency generations. Normal workflow creation removes stale stamps, but direct delivery and registered stamp decoding still admit the conflicting combination.

Change: define one workflow-identity invariant and reject conflicting identities before any handler, failure transition, or settlement mutation. Apply it consistently to both stores, coordinator, execution scope, and DB atomic settlement. Test repeated stamps and supplied IDs/indexes as well as chain-plus-batch combinations. Prefer a small method on the existing coherent envelope/workflow owner over a new validator hierarchy.

Acceptance: zero business execution or workflow mutation for a conflicting delivery; one identity governs all legal execution/failure/settlement paths; cancellation, handled redelivery, and duplicate terminal delivery retain their contracts.

### O04 — Older success closes a newer open circuit (high operational risk)

Owner: `src/Integration/CacheLayer/CircuitBreakerScope.php:42–75`.

Every successful invocation deletes both failure and open-state keys. A deterministic interleaving admitted call A, failed call B and opened the circuit at threshold 1, verified that another call was blocked, then completed A successfully. The next call was allowed despite the just-opened recovery interval.

Change: fence successful cleanup by the circuit state/generation admitted by that call. Ordinary successful calls must not erase an open state established after their admission. Only the currently owned recovery probe may close its matching open generation. Cover delayed successful/failed probes and expired probe ownership. Reuse CacheLayer's atomic counters and token locks; do not create a competing cache implementation.

Acceptance: replay the shared-state interleaving with real Redis and Valkey counters/locks; test state changes within the same clock second, multiple failures, recovery expiry, stale probes, and cleanup failure. Memcached remains covered for the token-lease capabilities it actually provides; it is not an atomic-counter circuit-state backend. Coordination failures after business success must not trigger automatic duplicate execution.

### O05 — In-memory workflow store permits duplicate message IDs (medium)

Owners: `InMemoryWorkflowStore::create()` and durable workflow creation parity.

A batch containing two copies of an envelope stamped `MessageIdStamp('same-id')` was accepted in memory and rejected by SQLite's unique message-ID constraint. The in-memory store's `findItemByMessageId()` can therefore resolve an ambiguous identity, and application tests can accept workflows that production SQL rejects.

Change: validate message-ID uniqueness within and across retained workflows before publishing in-memory state. Preserve intentional message-ID reuse for ordinary at-least-once queue deliveries; do not make queue IDs globally unique as an unrelated change. Keep durable creation atomic and document the same workflow identity requirement across stores.

Acceptance: duplicate IDs in one workflow and in separate workflows fail without partial insertion; unstamped messages receive independent IDs; lookup returns the unique owning item; existing stable-identity behavior stays green.

### O06 — Invalid Redis attempt metadata leaves a partial reservation (high)

Owner: `src/Integration/Redis/RedisTransport.php:32–72`, `RECEIVE` Lua.

The preflight checks whether the attempt field exists but not whether Redis can safely increment it. With `attempts[id] = 'garbage'`, receive moved the ID from ready to reserved before `HINCRBY` failed. The real Redis probe observed `ready=0`, `reserved=1`, `receipt_exists=false`; after visibility expiry, receive raised `RedisBackendStateCorruption` for the missing receipt. Atomic Lua execution prevents concurrent interleaving but does not roll back mutations preceding a command error.

Change: validate every selected record's attempt encoding/range, required fields, and relevant key types before the first mutation. Validate all records in a batch, including reclaim candidates. Account for Lua number precision and Redis signed-integer overflow without narrowing legal PHP attempt values accidentally. Return the existing explicit corruption outcome while leaving state unchanged. Do not substitute a process-memory queue when Redis is unavailable or corrupt.

Acceptance: missing, nonnumeric, negative, overflow-boundary attempts, wrong key types, valid neighboring records, and expired records on real Redis and Valkey. Compare all affected keys before/after rejected receive; no partial movement or receipt loss. Preserve valid reserve/reclaim/release/ack behavior and bounded limits.

### O07 — Active PHPStan complexity gate fails (release blocker)

Installed detector: `tomasvotruba/cognitive-complexity` **1.3.0**. Active budgets: class 80, function 12.

* `NativeWorkerPoolBackend::supervise()` at line 327: complexity **14**.
* `DBLayerTransport::acknowledgeWorkflow()` at line 57: complexity **14**.

Simplify these existing owners while implementing O01/O03, using cohesive private methods only when they clarify ownership or invariants. Do not raise budgets, downgrade the detector, add suppressions/baselines, move code outside the scan, or fragment behavior into pass-through classes. Run `composer ic:test:static` during iteration.

### O08 — Required SQL integration lanes can disappear during discovery (release-evidence gap)

Owners: `tests/Fixtures/IntegrationEnvironment.php:13–31,102–116`, `tests/Integration/DurableDriverMatrixTest.php`, `.github/workflows/security-standards.yml`, and `docs/testing.rst`.

`databaseDriverExpected()` excludes unavailable PDO drivers before checking missing configuration, and excludes MSSQL when `IC_MSSQL_USER` is absent. The subsequent GitHub Actions strict comparison therefore cannot detect those missing prerequisites. A fresh process with `GITHUB_ACTIONS=true` and no `pdo_sqlsrv` returned an empty MSSQL case list without error. This confirms a discovery gap, not that a particular hosted run omitted MSSQL. Redis/Memcached discovery already has stronger missing-extension checks.

Change: derive required service coverage from the explicit lane manifest, independently of installed extensions/credentials. Fail before test execution when a required driver, credential, service, or dataset is missing. Preserve ordinary optional local discovery and the smaller dedicated replica lane. Record the actual per-driver test inventory/results so an empty filtered suite cannot certify a backend. Review `fail_on_skipped_tests: false`; use the shared strict gate where appropriate, but do not mistake it for protection against tests that were never registered.

Acceptance: required SQL driver missing, MSSQL username missing, required service variables absent, empty replica filter, and correct configuration. Required lanes fail visibly; optional local runs and explicitly narrower lanes remain usable. The full release lane demonstrably executes MySQL, MariaDB, PostgreSQL, SQL Server, SQLite, Redis, Valkey, and Memcached coverage.

## Dependency adoption and ownership

The committed 3.0 dependency support baseline is:

| Dependency | Proposed declaration | Appropriate use |
| --- | --- | --- |
| UID 6.0 | Runtime `^6.0` | Continue using canonical, fork-aware `ULID::generateMonotonic()` for message, row, workflow, item, and claim identifiers. Preserve stored sizes and formats. Document its transitive 64-bit PHP / `ext-ctype` requirements. |
| CacheLayer 4.0 | Development/test `^4.0`; optional production integration in `suggest` | Keep detached token leases, overlap, atomic counters, rate limiting, and circuit policy. Host owns backend handles and worker-level Runwire binding. |
| DBLayer 6.0 | Development/test `^6.0`; optional production integration in `suggest` | Borrow supplied `Connection`, safe batch sizing, writer affinity, SQL compilation, transaction retry, and after-commit behavior. Use its passed-context API for cancellation/deadlines and cooperative backoff. |
| Runwire 2.1.1 | Development/test **`2.1.1`**; optional production integration in `suggest` | Borrow the host's explicit runtime/request/task scope, preserving standalone operation and host lifecycle ownership. |

Runwire, CacheLayer, and DBLayer remain optional consumer integrations. Update `suggest`, README, upgrade/integration/backends/operations/testing/performance docs, and runnable examples together. A development dependency does not constrain a consumer's optional installed dependency: declare and document supported adapter versions rather than relying on an incidental dev install.

Declare optional-package support at Composer resolution time without requiring the packages. The planned `conflict` values reject unsupported versions: CacheLayer `<4.0 || >=5.0`, DBLayer `<6.0 || >=7.0`, and Runwire `<2.1.1 || >=3.0`. This supports CacheLayer 4.x, DBLayer 6.x, and Runwire 2.x from 2.1.1. The development Runwire reference stays exactly `2.1.1`; separately test supported lowest/current stable resolution in isolated consumer fixtures. An absent optional package must remain a valid installation. Bootstrap checks should report a missing selected capability, not repeatedly introspect package versions on the request path.

Move `ext-pcntl` / `ext-posix` from universal runtime requirements to the standalone process-backend support contract in Batch 3. UID 6's real `php-64bit` / `ext-ctype` requirements remain enforced through Composer. `composer.lock` is currently ignored and untracked: refresh the local development resolution and retain exact locks in CI/evidence artifacts; do not silently change the reusable-library lockfile policy.

Do not add a Runwire-aware UID generator wrapper: the ULID path used here has no routine coordinated I/O wait that would benefit from it. UID's optional Runwire binding for coordinated generators does not need to be imposed on ordinary message-ID creation.

The exact released versions resolved together, and their existing-suite pass is a useful compatibility starting point. It is not proof of the newly requested composition behavior, broader dependency ranges, or all live drivers.

The abandoned annotations package is development tooling owned through PHPForge/PHPBench. Track its upstream replacement path; do not remove the required benchmark detector or replace shared tooling merely to hide abandonment.

## Explicit Runwire integration contract

### Entry and lifetime

Expose one small passed-instance binding contract for `RuntimeContext`, optional `RequestContext`, and optional `CoroutineScope`. Prefer an operation-bounded API on the existing bus/consumer integration owners, with a callback and restoration in `finally`, so long-lived services do not retain a completed request. An `ExecutionScope` implementation is justified for the handler boundary, but it must not be the only binding surface: dispatch, receive, retries, settlement, and worker idle waiting also need the applicable context.

API spelling should match the established sibling `withRunwire` convention. Any new production type must justify a concrete runtime-adaptation/lifetime boundary; use one cohesive binding owner rather than duplicate runtime state in every decorator or introduce generic capability/container hierarchies. Keep existing constructor names, positional arguments, synchronous return values, and default behavior.

Required forwarding paths:

```text
framework -> Omnibus
framework -> intermediary library -> Omnibus
framework -> Omnibus -> supplied DBLayer Connection / CacheLayer policies
```

Pass the **same object identities** through these paths. Do not discover a global current runtime or construct an implicit standalone replacement. Resolve optional dependency/capability availability once at binding/bootstrap, then use only the capabilities relevant to the operation.

Validate process identity, request/runtime identity, completed requests, cancellation/deadline state, and scope usability at binding and relevant operation boundaries. Reject stale/cross-process/inconsistent bindings; they are not an unavailable-capability fallback case. A numeric generation alone cannot prove that a context remains active: worker replacement requires the host to provide a fresh binding and stop admission under the old one.

Runtime/request/scope objects and local cancellation tokens must remain execution-local. Never serialize them in queue envelopes, persistent failure payloads, workflow records, or static request state. Durable messages get a new host-provided job scope when consumed; they do not inherit the producing HTTP request's lifetime.

### Capabilities and normal fallback

| Operation | Bound behavior | Unbound or capability-unavailable behavior |
| --- | --- | --- |
| Admission, dispatch, receive, handler entry | Check the supplied request/scope cancellation and minimum remaining deadline. | Existing ordinary path. |
| Worker idle/backoff waits | Use supplied usable coroutine scope when `RUNWIRE_COROUTINES` is available. | Existing bounded synchronous wait. |
| DB operations | Invoke supplied connection's `withRunwire($runtime, $callback, $request, $scope)` and restore its prior binding. Replace the transport's raw settlement-retry `usleep()` with DBLayer's context-aware wait under the new support floor. | Existing transaction/query behavior. |
| Cache policies | Forward through `RunwireIntegration::share()` only when the host has already bound that same runtime to CacheLayer. | Normal lock/counter policy behavior; never weaken shared uniqueness/rate/circuit guarantees. |
| Handler execution | Compose host cancellation/deadlines with the existing local timeout and user `ExecutionScope`; earliest deadline wins. | Existing scope and handler behavior. |
| Parallel work | Preserve current sequential message handling by default. | Existing sequential behavior. |

Capabilities do not make blocking PDO, native Redis, broker SDK calls, or arbitrary handlers asynchronously interruptible. Do not advertise otherwise. Use provider timeouts and cooperative checkpoints, and benchmark any later bounded handler concurrency separately. Concurrent tasks must not share one transaction-bound DB connection unsafely.

CacheLayer 4.0 has worker-level `bind()`/`release()` and operation-level `share()`. Omnibus may use a matching existing host binding, but must not globally bind, replace, flush, or release it. Its current lock providers do not by themselves turn every acquisition wait into a coroutine wait; keep wait policies bounded and prove any provider-specific adaptation before claiming that benefit.

### Host lifecycle and settlement

Keep `WorkerPool` / `RunwireWorkerPoolBackend` as explicitly selected standalone supervision. Its current `new SelectLoop()` and `new Supervisor()` must never be reached merely because a passed host context advertises concurrency or owns a worker pool. For host-managed workers, reuse `Worker::runManaged()` / lifecycle adapters and expose a bounded consumer operation that the host can schedule.

The integration must not start/run/stop a host loop, fork extra workers, reap host children, overwrite host signal handlers, close host resources, complete a supplied request, or close/cancel a supplied task scope. Only library-owned child tasks, if subsequently justified, may be cancelled by the library.

Cancellation stops new admission and business work. It must not be treated as an ordinary transient handler failure that automatically retries or records terminal failure. Separate admission, handler execution, durable mutation, and settlement outcomes. A committed send or successful side effect must not be reclassified as unexecuted merely because cancellation arrives afterward. Define a bounded worker-owned settlement/cleanup lifetime without clearing the host's cancellation state. If cleanup cannot complete, retain existing receipt/visibility recovery and require idempotency; never acknowledge unfinished work.

For prefetched deliveries, define stop behavior between messages and account for visibility consumed while earlier handlers run. Return unfinished reservations safely where supported, or leave them recoverable under the documented lease contract. Test cancellation during retry, lock acquisition, handler execution, failure persistence, and acknowledgement ambiguity.

After-response and after-commit callbacks must have an explicitly valid execution lifetime. Do not retain a completed HTTP `RequestContext` for a callback or future queued job. Keep DBLayer's transaction callback ownership and the existing runtime-provided deferral boundary.

## Additional updates included in 3.0

These are committed work areas, not confirmed vulnerabilities. The follow-up inspection checked actual worker signal calls, overlap renewal timing, class-map caches, queue indexes, workflow-store maintenance APIs, optional-package assertions, and CI discovery. Implementation must distinguish a demonstrated defect from a supported configuration limit or an optimization proposal.

| ID | Work and owner | Required outcome |
| --- | --- | --- |
| U01 | Process-extension portability: Composer, `Worker`, `WorkerOptions`, native/Runwire pool bootstrap, `CoreRuntimeIndependenceTest`. | Core dispatch/consume and host-managed workers install and run without PCNTL/POSIX. Standalone native supervision requires them and fails clearly before signals/forking. Define automatic/default signal behavior and an explicit opt-in failure contract; document any changed option default/type. |
| U02 | Lease budgets: `Consumer`, `WorkerOptions`, `OverlapProtectionScope`, detached uniqueness, workflow/failure retry claims. | Test slow handlers, prefetch waiting, visibility expiry, lock loss, and claim expiry. Document safe TTL/prefetch sizing and add justified cooperative checkpoints or host-owned renewal where supported. Post-handler refresh alone must not be described as continuous overlap protection. |
| U03 | Codec work: `CallbackMessageCodec`, callback stamp codecs, `JsonEnvelopeSerializer`. | Profile duplicate JSON encoding and allocation cost. Ship a simpler bounded validator only if it improves representative throughput while preserving string-map, UTF-8, depth, cycle, object/resource, and nonfinite-value rejection; otherwise retain the safe implementation and record measurements. |
| U04 | Structural cleanup: the 14 reported clone groups / 6.29% duplicated lines and touched complex owners. | Review every reported group; centralize actual shared invariants and update callers. Record why legitimate provider/value/exception boundaries remain separate. Pass the original detector without exclusions, suppressions, or meaningless class fragmentation. |
| U05 | Persistent lookup state: `HandlerMap`, `RouteMap`, `ListenerMap`. | Measure finite configured-class reuse and supported dynamic-class hosts. Bound additional lookup-cache growth only if the workload establishes a real problem; retain correct inheritance/interface resolution and document that PHP itself retains generated classes. Do not promise that cache eviction unloads classes. |
| U06 | Recovery semantics/observability: `FailureManager`, `WorkflowCoordinator`, broker/unique send, cross-store acknowledgement, telemetry decorators. | Test uncertain sends, expired claims, partial settlement, stale tokens, and best-effort workflow events. Provide usable idempotency/reconciliation guidance and bounded diagnostics through existing extension points; protect payloads, secrets, and tenant isolation. Preserve after-side-effect nonretryable outcomes. |
| U07 | Storage and retention: DB receive/claim/failure queries, `QueueSchema`, durable workflow/failure stores, operations docs. | Measure plans and contention with representative backlog, delayed/reserved rows, and terminal history on real drivers. Add indexes or bounded maintenance only when justified; preserve active receipts, retry claims, terminal deduplication, and workflow identity retention. Publish a migration/cutover procedure for any actual schema change. |
| U08 | Consumer packaging/interoperability: Composer constraints, archive, docs/examples, PSR boundaries, CI artifacts. | Test a clean consumer with no optional packages and consumers with each selected integration, including framework/intermediary forwarding and an independent PSR implementation. Verify packed artifacts, optimized autoloading, supported-platform checks, current dependency instructions, and 3.0 upgrade examples. |

No new NATS/Kafka/AMQP/SQS SDK adapter, default parallel handler execution, timer supervisor, or general-purpose integration framework is included without a concrete requirement. A major release allows necessary breaks; it does not waive the engineering principles' measurement and ownership requirements.

## Implementation batches and acceptance gates

| Batch | Deliverable | Depends on | Findings / updates | Status |
| --- | --- | --- | --- | --- |
| 0 | Reliable inventory and reproducible 2.6 baselines | Audit | O08, baseline evidence | **Complete** |
| 1 | Queue, storage, and workflow integrity | 0 | O02, O03, O05, O06; DB part of O07 | **Complete** |
| 2 | Child ownership, circuit recovery, remaining static repairs | 1 | O01, O04; remaining O07 | **Complete** |
| 3 | New dependency baseline and portable core | 2 | UID 6, CacheLayer 4, DBLayer 6, Runwire 2.1.1; U01 | **Complete** |
| 4 | Passed-instance Runwire composition | 3 | Full runtime contract above | **Complete** |
| 5 | Lease, cancellation, ambiguous-delivery recovery | 4 | U02, U06 | **Complete** |
| 6 | Measured performance, structure, and storage improvements | 5 | U03, U04, U05, U07 | **Next** |
| 7 | 3.0 migration, executable examples, consumer packaging | 6 | U08, all public changes | Pending |
| 8 | Exact-candidate release certification | 7 | All release gates | Pending |

Implement in this order; keep source-mutating processors sequential and reuse successful analyzer results. Each batch should be reviewable with its production changes, focused regressions, updated relevant docs, and recorded validation. Any production change after certification requires checks appropriate to that change and renewed final-SHA evidence.

### Batch 0 — Strict coverage and baseline capture

Owners: integration fixtures, repository CI wrapper, existing benchmark/soak scripts, evidence tracker.

- [x] Fix O08 with explicit required-service manifests for full, replica, and optional local lanes. Focused discovery regressions now fail required lanes on missing drivers/credentials/datasets; the full manifest accounts for MySQL, MariaDB, PostgreSQL, SQL Server, SQLite, Redis, Valkey, and Memcached, while the replica lane remains intentionally narrower.
- [x] Capture the current 2.6 revision, exact resolved direct integration versions, PHP/extensions, PHPForge workflow/service image versions, historical test inventory, expanded strict-matrix evidence, and the two inherited O07 failures. The repository intentionally keeps no tracked `composer.lock`; exact CI resolution metadata is retained instead of changing that policy.
- [x] Establish request-host and durable-consumer baselines **before production changes**, with cold/warm HTTP paths, validated success/failure responses, concurrency 1/2/4, and a 2,000-message two-connection durable SQLite drain.
- [x] Declare workload-specific throughput, tail-latency, error/timeout, queue-growth, CPU/connection, and RSS budgets. Stable matched-environment release comparison keeps the plan-wide maximum 2% median successful-RPM regression; resource/tail budgets are recorded with the baseline artifact.
- [x] Preserve baseline metrics, environment/harness metadata, dependency/service versions, budgets, and source/run identities in `docs/evidence/omnibus-2.6-baseline.json`. PHPForge representative benchmark validation passed on PHP 8.4 and 8.5.

Exit gate: **met.** Missing required backends fail discovery, the intended full matrix is explicitly accounted for, and the committed baseline artifact fixes the comparison inputs. The two O07 complexity violations remain labelled as inherited 2.6 release blockers and are not accepted as candidate failures.

### Batch 1 — Queue, storage, and workflow integrity

Owners: DB/Redis transports, `StoredPayload`, failure hydration, workflow stores/coordinator/scope, focused settlement tests.

- [x] Turn O02/O03/O05/O06 reproductions into regressions that fail against the audited code.
- [x] Handle malformed DB wrappers inside the poison boundary, keep healthy neighbors consumable, preserve bounded inspection bytes, and persist terminal failure before destructive rejection.
- [x] Enforce one legal workflow identity before handlers or mutations; reject conflicting/repeated stamps and duplicate in-memory workflow message IDs without partial insertion.
- [x] Preflight Redis/Valkey batch metadata and key types before mutations, including exact increment boundaries and reclaim candidates; compare all affected keys on rejection.
- [x] Simplify `DBLayerTransport::acknowledgeWorkflow()` under the unchanged O07 budgets while retaining same-connection atomic settlement and cross-store recovery semantics.
- [x] Run relevant SQL tests on all durable drivers and Lua tests on real Redis/Valkey; retain legacy/binary envelope compatibility and stale-receipt protections.

Exit gate: **met.** O02/O03/O05/O06 regressions pass under the strict service matrix; malformed storage wrappers no longer strand healthy neighbors; corrupt Redis/Valkey receive preflight leaves state unchanged; and the DBLayer O07 violation is resolved. Exact-SHA GitHub Actions on `49944de40c7197631f8953e083f172802b67aaa0` passed all four QA combinations, both representative benchmarks, clean install, and replica-affinity. Analysis reports only the native `NativeWorkerPoolBackend::supervise()` complexity violation, intentionally carried into Batch 2.

### Batch 2 — Process ownership and shared circuit state

Owners: `NativeWorkerPoolBackend`, `CircuitBreakerScope`, fork-safety/policy fixtures.

- [x] Fix O01 by reaping only owned PIDs; cover unrelated child status, concurrent owned exits, EINTR, missing owned children, restart exhaustion, clean recycle, stop-time restart cancellation, and shutdown escalation.
- [x] Fence O04 success/probe transitions with admission generation and current token ownership. Reproduce stale success and expired-probe interleavings deterministically on shared providers.
- [x] Check standalone Runwire supervisor child ownership separately, using its released implementation and tests. Keep host-managed operation outside standalone supervision.
- [x] Resolve the native `supervise()` O07 violation through cohesive private logic; keep class 80 / function 12 and the installed detector.
- [x] Run focused lifecycle/cache tests, `composer ic:test:static`, and the full existing quality suite; retain signal restoration and primary-exception behavior.

Exit gate: **met.** O01/O04 and both O07 failures are closed. The native backend reaps only its owned PID registry and preserves unrelated host children; circuit success cleanup is generation-fenced and recovery probes must retain current token ownership. Redis/Valkey real-provider interleavings pass, Memcached remains covered for its supported token-lease contract, and released Runwire standalone supervision is explicitly documented as process-wide rather than host-composable. Exact-SHA GitHub Actions run `37648347700` on `d73a85c90648014e64b24bff9e5a25bc165531fa` passed both analysis jobs, all four QA combinations, both representative benchmarks, clean install, and replica-affinity.

### Batch 3 — Dependency adoption and portability

Owners: Composer declarations, adapter bootstrap, `Worker`/options/pools, core independence tests, integration matrix.

- [x] Adopt the dependency table and optional `conflict` policy, refresh the local resolution, and update package suggestions/platform documentation. Do not commit an ignored library lockfile accidentally.
- [x] Implement U01: remove universal PCNTL/POSIX requirements, keep standalone native capabilities explicit, and make ordinary/default worker execution safe when signal functions/constants are unavailable. A caller explicitly requesting unsupported signal handling gets an actionable startup error.
- [x] Verify genuine no-PCNTL/no-POSIX production installation and execution; a mock with those extensions still loaded is insufficient. Preserve supported standalone Unix signal behavior.
- [x] Test exact released dependencies, lowest supported resolution, and current stable supported ranges on PHP 8.4/8.5. Prove optional packages remain optional in isolated consumers.
- [x] Revalidate DBLayer batch sizing, writer affinity, query/transaction attempt limits, nested after-commit/rollback callbacks, and binary payloads on each SQL driver.
- [x] Revalidate CacheLayer detached/token leases, retry TTL extension, atomic counters, overlap loss, rate limiting, and circuit recovery on Redis/Valkey/Memcached; check cold/warm and post-fork UID generation with unchanged durable IDs.

Exit gate: **met.** Composer resolves UID 6.0, CacheLayer 4.0, DBLayer 6.0, and Runwire 2.1.1 on PHP 8.4/8.5 under lowest and stable dependency modes; unsupported optional generations are rejected by explicit conflicts. Production `--no-dev` installation installs UID only from the Infocyph integration stack and its platform check requires `ext-ctype`, PHP, and 64-bit PHP but not PCNTL/POSIX. The CI-only Alpine PHP 8.4 probe executes a normal Worker with PCNTL/POSIX genuinely absent and verifies explicit signal/native-pool requests fail cleanly. The strict SQL/Redis/Valkey/Memcached matrix remains green, and the UID 6 fixed-timestamp fork regression proves post-fork monotonic state separation without changing the canonical 26-character ULID format. Exact-SHA GitHub Actions run `37651523235` on `cbe521d1f701cd779d7c3669770532c645fdba5b` passed both analysis jobs, all four QA combinations, both representative benchmarks, clean install, and replica-affinity. Intentional signal-default changes are documented in the 3.0 migration guide.

### Batch 4 — Borrow the host's Runwire context

Owners: existing bus/consumer/worker entry points, minimal cohesive binding owner, execution scopes, DB/Cache integration forwarding.

- [x] Implement the operation-bounded passed-instance contract for runtime/request/scope, with `finally` restoration and no global discovery.
- [x] Cover framework-to-Omnibus and intermediary forwarding with exact object identity, nested bindings, throws, cross-runtime requests, stale/completed scopes, process changes, and host-provided replacement contexts.
- [x] Extend context coverage across dispatch, receive, handler admission, retry, durable mutations, settlement, and idle waits. Use DBLayer's `withRunwire()`/cooperative backoff and CacheLayer's matching host-owned `share()` boundary.
- [x] Preserve normal behavior when Runwire or a relevant capability is absent; distinguish absence from invalid binding. Test coroutine-capable and synchronous fallback waits without inventing asynchronous PDO/SDK guarantees.
- [x] Keep standalone supervisor selection explicit. Verify no implicit loop start/stop, forks, foreign child reaping, host signal changes, resource closure, request completion, or borrowed scope cancellation.
- [x] Reuse one bus/consumer across requests/tasks/tenants and check that runtime, attributes, leases, and connection state are restored; use task-isolated leased DB connections where concurrency requires them.

Exit gate: **met.** `RunwireBinding` is the single fiber-local passed-instance owner used by `MessageBus`, `Consumer`, and `Worker`; it restores nested/throwing bindings, rejects cross-runtime/completed/cancelled/stale-generation/cross-process admissions, and isolates concurrent request contexts. Registered DBLayer 6 connections borrow the exact runtime/request/scope through native `Connection::withRunwire()`; CacheLayer 4 execution is shared only when the host has already bound that exact runtime. Worker idle waits and DB settlement backoff use borrowed coroutine/cooperative waits with synchronous fallback, without starting loops or supervisors. Exact-SHA GitHub Actions run `37655035078` on `88762effecc3c86adf1b600a5ffa6515f2e86f12` passed both analysis jobs, all four QA combinations, both representative benchmarks, clean install, and replica-affinity.

### Batch 5 — Lease and recovery semantics

Owners: consumer/worker boundaries, execution scopes, uniqueness/overlap, failure/workflow claim paths, deferred dispatch, telemetry.

- [x] Implement the phase-specific cancellation contract: stop new work, combine the earliest host/local deadline, distinguish committed success from unexecuted work, and use bounded worker-owned cleanup without clearing host cancellation.
- [x] Cover cancellation during receive, lock acquisition, retry/backoff, handlers, failure persistence, acknowledgement, and between prefetched messages; leave unfinished receipts safely recoverable.
- [x] Finish U02 with measured handler/prefetch/TTL budgets, real lease-loss interleavings, and safe defaults/guidance. Add renewal only where a real provider/host capability supports it without library-owned background loops.
- [x] Finish U06 with ambiguous send, retry-claim expiry, stale-token settlement, cross-store reconciliation, and notification-failure tests. Preserve at-least-once guarantees and explicit nonretryable post-execution failures.
- [x] Add useful bounded recovery diagnostics through existing telemetry/events and executable idempotency guidance; retain payload confidentiality and control metric cardinality.
- [x] Test after-response and after-commit callback lifetime separately from the originating request; queued consumption receives a fresh host job scope.

Exit gate: **met.** Host cancellation is checked at admission/handler boundaries and is never converted into an automatic retry. Once business execution returns successfully, acknowledgement runs inside a bounded five-second worker-owned Runwire cleanup request while the original request remains cancelled, then cancellation is surfaced before the next prefetched item. Local deadline scopes inherit the earlier active host deadline/cancellation. Ambiguous unique, failure-retry, and workflow sends retain the attempted lease/claim until expiry; unattempted workflow claims are released immediately; stale claim tokens cannot settle newer ownership. Lease/prefetch sizing and idempotent reconciliation guidance are documented, and after-response/after-commit lifetime behavior is covered explicitly. Exact-SHA GitHub Actions run `37707636584` on `38191540c26af6dfe51f28e9c5b9219c9d7a545f` passed both analysis jobs, all four QA combinations, both representative benchmarks, clean install, and replica-affinity.

### Batch 6 — Performance, structure, and storage review

Owners: serializers/codecs, map caches, detector-reported clone owners, durable query/schema paths, existing benchmark harnesses.

- [ ] Finish U03 with before/after profiles and valid/invalid/cyclic/UTF-8 payload regressions. Keep a validator change only when representative gains justify it and all serialization limits remain enforced.
- [ ] Finish U04 by reviewing all reported clone groups and centralizing actual duplicated invariants; review newly touched types/call hops using PHPForge. Do not optimize the source-file count.
- [ ] Finish U05 with persistent-worker lookup/memory measurements; implement a bound only where supported workloads justify it, preserving map resolution and finite-class warm performance.
- [ ] Finish U07 with driver-specific query plans, realistic backlog/history sizes, contention, safe batch limits, and retention analysis. Do not prune active claims or remove terminal records needed for redelivery identity.
- [ ] Compare dependency-only, unbound 3.0, and bound 3.0 behavior against Batch 0 on the same workload/environment; include normal paths, contention, cache hit/miss, failures, and retry paths.
- [ ] Record a disposition for every U03/U04/U05/U07 subitem: shipped change plus evidence, or measured/source-supported reason to retain the existing design. Revert insignificant/regressive optimizations.

Exit gate: every review area has a disposition, accepted changes meet workload budgets, and no security/ownership/compatibility invariant was weakened to gain throughput. Any schema change includes tested migration and rollback limits.

### Batch 7 — Migration, docs, and consumer artifacts

Owners: README, existing Sphinx docs, documentation example tests, new executable examples where useful, consumer/packaging fixtures.

- [ ] Add the 3.0 upgrade guide: dependency floors, optional extensions, signal options, passed-context lifetime, cancellation outcomes, lease sizing, deployment/cutover, and intentional breaks. Retain relevant 2.6/2.5 storage history.
- [ ] Update integration/backend/operation/testing/performance docs and remove stale current-release dependency claims or feature-freeze wording; keep genuinely future broker adapters scoped separately.
- [ ] Add executable direct/intermediary forwarding, host-managed consumer, coroutine/fallback, no-optional-package, failure recovery, and idempotency examples. Keep source and docs examples synchronized.
- [ ] Complete U08 with clean `--no-dev` consumer installs, each selected optional integration, independent PSR clock/event interoperability, and genuine FPM plus a representative persistent host.
- [ ] Verify Composer archive contents, PSR-4/casing, production optimized autoloading, platform requirements, and absence of development-only files/dependencies. Do not force authoritative classmaps into consumers that generate classes.
- [ ] Build Sphinx strictly and run executable documentation/consumer tests independently of the library's dev autoloader.

Exit gate: a consumer can follow the upgrade and forwarding examples successfully, package artifacts install cleanly, and documentation accurately describes the delivered 3.0 behavior.

### Batch 8 — Certify the final 3.0.0 candidate

Owners: repository CI/PHPForge integration, matched performance environment, final evidence and release notes.

- [ ] Run the installed PHPForge flow: `ic:process`, `ic:tests:details`, then `ic:release:guard`; inspect generated changes and resolve every detector finding without weakening gates. Run `composer validate --strict` and a fresh live dependency advisory audit.
- [ ] Pass required PHP/dependency/service lanes and the dedicated deliberately lagging-replica job. Record actual backend case counts; separate local host, prepared-container, and hosted evidence.
- [ ] Exercise actual host-managed lifecycle replacement, cancellation, completion, FPM requests, and standalone pool shutdown. Repeat clean-production and packed-consumer checks on the final candidate.
- [ ] Benchmark repeated stable matched trials with readiness/warm-up and steady-state windows. Count only correct successful host requests in RPM; report jobs/s separately. Capture p50/p95/p99, errors/timeouts, queue growth, connections, CPU, and continuous live process-tree RSS during load.
- [ ] Enforce the **maximum 2% median successful-RPM regression** and Batch 0's workload-specific resource/tail budgets in a stable matched environment. A disabled/noisy-runner comparison leaves the performance gate open.
- [ ] Run at least **300 seconds** of representative persistent request/job execution, with no progressive queue/resource growth, unintended duplicate side effects, unreaped owned children, or active lease/context retention. Check allowed at-least-once redelivery through verified idempotency.
- [ ] Record final immutable candidate SHA, exact dependency locks, commands, PHP/extensions/services, harness versions, and CI artifacts. Required lanes must certify that SHA; historical or pre-final green runs cannot close the release gate.
- [ ] Prepare 3.0.0 release notes and a finding/update checklist. Tag/publish 3.0.0 only after all mandatory gates close and publication is authorized; do not alter prior tags.

Exit gate: all O01–O08 fixes, mandatory feature/portability work, and U01–U08 dispositions are complete; exact-candidate quality, composition, service, performance, documentation, and artifact evidence pass. Implementation completion, local validation, hosted certification, and publication remain separately recorded states.

## Audit artifacts and reproducibility

The audit left production PHP and Composer files unchanged. Temporary evidence from this session:

* `/tmp/omnibus-audit-quality-host.log` — completed detailed QA, including exact static failures and test counts.
* `/tmp/omnibus-audit-release-guard.log` — final guard exit 1 with the same two PHPStan blockers.
* `/tmp/omnibus-audit-advisories-live.json` — live lockfile advisories and abandonment.
* `/tmp/omnibus-audit-next/composer.json`, `composer.lock`, and `/tmp/omnibus-audit-next-install.log` — isolated released dependency resolution.
* `/tmp/omnibus-audit-next-tests.log` — current tests against isolated dependencies.
* `/tmp/omnibus-audit-probes-current.log` and `/tmp/omnibus-audit-probes-next.log` — DB corruption, conflicting identities, circuit interleaving, and store parity reproduction results.
* `/tmp/omnibus-audit-redis-probe.log` — live malformed-attempt corruption reproduction result.
* `/tmp/omnibus-audit-benchmarks.log`, `-consumer-soak.log`, `-durable-soak.log`, and `-workflow-soak.log` — supporting baseline smoke measurements.

For isolated compatibility reproduction, install only the requested dependencies in a temporary Composer project, mapping the Omnibus source/test namespaces to this checkout, then run:

```sh
php vendor/bin/pest \
  --configuration vendor/infocyph/phpforge/resources/pest.xml \
  --bootstrap /tmp/omnibus-audit-next/vendor/autoload.php tests
```

Temporary probe scripts were removed after recording their results. The finding descriptions specify their setup, interleaving, and observed failure; Batches 0–2 must preserve those reproductions as repository regressions. O08 was reproduced with an isolated PHP command and created no probe file. The remaining temporary files are session evidence, not durable release artifacts. Batches 0 and 8 must retain reproducible result artifacts through the repository CI/PHPForge workflow. All implementation checkboxes remain open.

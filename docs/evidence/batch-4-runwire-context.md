# Batch 4 Runwire host-context certification

Batch 4 implements host-owned Runwire composition without converting Omnibus into a runtime owner.

## Delivered boundary

- `RunwireBinding` is the single operation-bounded context owner.
- `MessageBus`, `Consumer`, and `Worker` accept/use the same binding instance and expose `withRunwire(...)`.
- Context is fiber-local with a root fallback and is restored in `finally`.
- Exact `RuntimeContext`, `RequestContext`, and optional `CoroutineScope` object identities are forwarded.
- Cross-runtime requests, completed/cancelled requests, stale worker generations, and mismatched process IDs are rejected.
- Nested calls preserve the current host owner; concurrent fibers cannot observe another request's binding.

## Dependency forwarding

DBLayer 6 integrations register their supplied `Connection` with the binding. During bound Omnibus operations each connection uses its native `Connection::withRunwire()` contract, so DBLayer owns cancellation/deadline handling and restoration.

CacheLayer 4 is not globally bound by Omnibus. When the host has already bound the same runtime, Omnibus uses `RunwireIntegration::share()` for the operation and restores CacheLayer execution context afterward.

Worker idle waits use a supplied Runwire coroutine scope only when the runtime exposes `RUNWIRE_COROUTINES`; otherwise the existing bounded synchronous wait remains. DBLayer settlement retry now uses `Connection::cooperativeSleep()`.

Standalone `RunwireWorkerPoolBackend` remains explicit and separate. Host-context binding never starts/stops a Runwire loop, supervisor, worker pool, or host request.

## Regression coverage

Focused tests cover:

- exact runtime/request forwarding through MessageBus, DBLayer, and CacheLayer;
- reuse of one Consumer across completed requests with no retained request context;
- cross-runtime, completed, cancelled, stale-generation, and wrong-PID rejection;
- nested binding and exception restoration;
- concurrent Fiber request isolation;
- coroutine-backed Worker idle waiting and synchronous fallback.

## Exact certification

Implementation SHA: `88762effecc3c86adf1b600a5ffa6515f2e86f12`

GitHub Actions run: `37655035078`

Passed:

- PHP 8.4 analysis;
- PHP 8.5 analysis;
- PHP 8.4 prefer-lowest QA;
- PHP 8.4 prefer-stable QA;
- PHP 8.5 prefer-lowest QA;
- PHP 8.5 prefer-stable QA;
- PHP 8.4 representative benchmark;
- PHP 8.5 representative benchmark;
- clean install;
- replica writer-affinity.

No PHPForge detector, complexity budget, or release constraint was weakened.

Upgrading
=========

3.0.0
-----

Omnibus 3.0 raises the supported integration floor to UID 6, CacheLayer 4,
DBLayer 6, and Runwire 2.1.1. CacheLayer, DBLayer, and Runwire remain optional
consumer integrations. Composer rejects unsupported installed generations
rather than requiring those packages for consumers that do not select them.

``ext-pcntl`` and ``ext-posix`` are no longer universal package requirements.
Ordinary request/FPM, dispatch, ``Consumer``, and single-process ``Worker``
usage can install without them. ``WorkerOptions::handleSignals`` now defaults
to ``false``. Applications that relied on Worker-installed SIGTERM/SIGINT
handlers must opt in explicitly:

.. code-block:: php

   $worker = new Worker(
       $consumer,
       new WorkerOptions(handleSignals: true),
   );

That opt-in requires PCNTL. The standalone native ``WorkerPool`` requires
PCNTL/POSIX and fails immediately with an actionable capability error when they
are unavailable. ``RunwireWorkerPoolBackend`` remains explicitly selected and
targets Runwire 2.x from 2.1.1; installing Runwire never changes the selected
backend automatically.

UID 6 preserves Omnibus's canonical monotonic ULID storage format while adding
its own Composer-enforced 64-bit PHP and ``ext-ctype`` platform requirements.
No durable identifier/schema migration is introduced by this dependency bump.

Database and coordination adapters now target DBLayer 6.x and CacheLayer 4.x.
The adapter ownership boundaries remain unchanged: DBLayer owns database
execution/transaction policy, CacheLayer owns lock/counter primitives, and
Omnibus owns delivery/workflow semantics.

3.0 deployment and host lifecycle
---------------------------------

Use a coordinated cutover where all queue readers, failure-retry tools, and
workflow dispatchers share durable storage. Inventory every participating
process and upgrade the required packages together:

.. code-block:: console

   composer require infocyph/omnibus:^3.0
   composer require infocyph/dblayer:^6.0
   composer require infocyph/cachelayer:^4.0
   composer require infocyph/runwire:^2.1.1

The last three packages are **optional**: install only those used by the
application. Do not keep older DBLayer, CacheLayer or Runwire generations in an
application that loads their Omnibus adapters. Test the PHP extension
capabilities actually used by the host, rather than requiring PCNTL/POSIX in
every FPM deployment.

Before cutover, stop old consumers and producers, drain/record in-flight
reservations, inspect failed-message retry state, and back up durable queue,
workflow and failure records. Upgrade all workers, administrative/replay
commands and producers accessing the same stores before restarting traffic.
Omnibus 3.0 introduces no new default schema; however, a 2.5 process **must
not** read 2.6+ wrapped payloads. The historical 2.6 compatibility and
rollback restrictions below continue to apply. Validate rollback using a
restored/converted dataset and reconcile durable side effects; do not assume
an application-code rollback alone restores compatibility.

For host-owned requests, pass the already-created Runwire
``RuntimeContext`` and the active ``RequestContext`` to
``MessageBus::withRunwire()``, ``Consumer::withRunwire()`` or
``Worker::withRunwire()``. Do not construct a new runtime on every message,
do not use a completed request, and do not reuse request contexts between
successive jobs. Bind a new request for each host operation and call
``RequestContext::complete()`` in ``finally`` when the host owns it.
A Fiber must receive the runtime/request explicitly: ambient state in the
parent Fiber is not automatically forwarded. Without a coroutine scope,
Runwire-aware sleep uses the cooperative fallback; it cannot interrupt
arbitrary blocking PHP I/O.

Cancellation before handler admission is surfaced to the host without an
automatic retry. After successful handler execution, Omnibus attempts
acknowledgement/settlement inside a bounded worker-owned cleanup request;
failure of settlement leaves at-least-once reconciliation to the durable
reservation and idempotent handler. A cancelled original request must never
process the next prefetched message. Set the visibility timeout above the
measured p99 handler, settlement, serial prefetch waiting and jitter budgets.
Set workflow dispatch/retry and uniqueness leases above the full send/recovery
window; do not assume lease auto-renewal where the provider has none. Treat
ambiguous delivery as at-least-once and reconcile via stable message/business
IDs and conditional ownership, not blind resend.

Host-managed workers use ``Worker::runManaged($lifecycle)`` or bounded
``Consumer::run()``. Explicit signal handling (disabled by default) remains
the responsibility of a CLI process with PCNTL; FPM and host-managed workers
should not install their own global handlers. See :doc:`consumer-validation`
for executable examples and independent consumer/FPM checks.

2.6.0
-----

Omnibus 2.6 raises its optional integration test floors to CacheLayer 3.4 and
DBLayer 5.1. Applications that do not construct those adapters keep no runtime
dependency on either package.

Runwire 1.x is an optional alternative ``WorkerPool`` backend. It is not a core
runtime dependency and is never selected merely because it is installed.
Omnibus 2.6 now requires ``ext-pcntl`` and ``ext-posix`` at the package
level. The native Unix pool uses those extensions directly; ordinary
FPM/request, direct dispatch, ``Consumer``, and single-process ``Worker``
paths do not construct a pool backend, but their installation still inherits
the mandatory process-extension floor.

``WorkerPool`` now accepts an optional backend, parent ``WorkerLifecycle`` and
lifecycle polling interval. Existing construction continues to select the
native PCNTL/POSIX backend. To opt into Runwire, pass
``RunwireWorkerPoolBackend`` explicitly.

Durable DB queue, workflow and failure payloads are stored through a versioned
portable text wrapper so arbitrary serializer bytes remain safe across the
supported DB drivers. Existing non-prefixed rows remain readable. Applications
with custom SQL that reads Omnibus payload columns directly must treat those
columns as Omnibus-owned encoded storage rather than application JSON.

Deploy this storage change through a coordinated cutover. Omnibus 2.5 readers
cannot decode the 2.6 wrapper: an old queue worker can treat a new message as
poison, and old workflow/failure-store readers cannot read the wrapped payloads.
Do not run 2.5 readers against shared durable storage after 2.6 writers start.

Pause producers and gracefully stop workers, workflow dispatchers and
failure-retry processes. Upgrade every application and administrative process
that accesses these durable stores before resuming processing and writes.
Existing unprefixed rows can remain; 2.6 readers support them.

After 2.6 has written wrapped rows, rolling back application code alone to 2.5
is unsafe. Keep processing paused until a separately verified data conversion
or restore procedure makes all queue, workflow and failure payloads compatible
with the target version. A restore must also account for messages and side
effects produced since the backup; restoring a snapshot alone is not a lossless
rollback procedure.

Failure-store re-failure semantics now reject older generations and reset stale
retry state only when a newer attempt/time wins. Redis/Valkey structural
corruption is distinguished from a structurally valid poison payload, and
redispatch replaces transient route/handled stamps instead of accumulating
them.

2.5.0
-----

DBLayer database integrations now use DBLayer 5.x as their supported and tested
baseline. DBLayer remains optional; applications that do not construct a
DBLayer adapter have no new dependency. Applications using database queues,
workflows, failure storage, or after-commit dispatch should update with:

.. code-block:: console

   composer require infocyph/dblayer:^5.0

DBLayer is now the sole owner of transaction retry. Omnibus no longer wraps a
three-attempt DBLayer transaction in a second retry loop, preventing a configured
three attempts from becoming as many as nine callback executions. Conditional,
single-statement acknowledgement/reject and release query retries remain
separate from transaction retry.

Queue ``receive()`` and workflow ``claimPending()`` still accept limits up to
1,000, but these values are maxima. The DBLayer adapters cap the row selection
before the atomic ownership update according to DBLayer 5's effective bind
limit. A call may therefore return fewer rows on a deliberately restricted
connection. Workflow multi-row insertion uses the same adaptive bind sizing.

No application-level migration is expected for ``MessageBus``, ``Transport``,
``WorkflowStore``, ``FailureStore``, ``Consumer``, or ``Worker``. Existing
database schemas and driver-specific locking behavior are unchanged.

2.4.0
-----

Omnibus 2.4 adds the optional ``WorkerLifecycle`` integration boundary for
portable heartbeat and cooperative external-stop polling:

.. code-block:: php

   $worker = new Worker(
       consumer: $consumer,
       options: $options,
       lifecycle: $hostLifecycle,
   );

The new constructor argument is nullable and appended, so existing Worker
construction remains compatible. ``WorkerOptions``, signal handling, and
``WorkerPool`` are unchanged. External stop requests take effect at safe worker
loop boundaries and do not preempt a running handler.

2.3.0
-----

Omnibus 2.3 adds the public ``HandlerContext``, ``HandlerMiddleware``, and
``HandlerInvoker`` APIs. Handler callables remain callables and keep their
existing synchronous and asynchronous arguments.

``Consumer`` and ``SyncTransport`` now require a ``HandlerInvoker`` instead of a
``HandlerMap``. Wrap the map once and share the invoker when both paths should
use the same middleware:

.. code-block:: php

   $invoker = new HandlerInvoker($handlers, $middleware);

   $sync = new SyncTransport($invoker);
   $consumer = new Consumer(
       $receiver,
       $invoker,
       $retry,
       $failures,
       $clock,
   );

No changes are required to ``MessageBus``, ``Sender``, ``Receiver``, retry
strategies, failure stores, ordinary PSR event listeners, or handler classes.

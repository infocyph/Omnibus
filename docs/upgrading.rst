Upgrading
=========

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

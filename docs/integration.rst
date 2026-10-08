Standalone integration
======================

Omnibus is a library, not an application kernel. Requiring it does not require
or initialize a framework, service container, HTTP stack, session system, or
command package.

Manual composition
------------------

Construct only the selected objects:

.. code-block:: php

   $serializer = new JsonEnvelopeSerializer($messageCodecs, $stampCodecs);
   $transport = new RedisTransport($redisClient, $serializer, $clock);
   $failures = new DBLayerFailureStore($connection, $serializer);
   $invoker = new HandlerInvoker($handlers, $middleware);
   $consumer = new Consumer(
       $transport,
       $invoker,
       $retryStrategy,
       $failures,
       $clock,
       $executionScope,
   );

The same constructors work with a hand-written bootstrap, any PSR-compatible
container, or a framework adapter.

Share one ``HandlerInvoker`` between ``SyncTransport`` and ``Consumer`` when
both execution paths should use the same ordered middleware objects. Construct
process-bound middleware and its network resources inside a ``WorkerPool``
worker factory after fork.

DBLayer integration
-------------------

The optional database adapters target DBLayer 6.x and use an existing
``Connection``. Migrations execute ``QueueSchema`` statements outside Omnibus
runtime paths. DBLayer owns driver behavior, bind sizing, database execution,
transaction retry, and after-commit callback lifecycle. Omnibus owns message
reservations, workflow/failure transitions, and delivery guarantees.

Queue receive and workflow claim operations derive an effective batch from the
connection before selecting rows, so their dynamic-ID update remains one atomic
operation within DBLayer's bind budget. Mutation-dependent reads remain
writer-affine and coordination queries are not cached. Dispatch-after-commit
uses only ``AfterCommitDispatcher`` and the connection's transaction callbacks.

CacheLayer integration
----------------------

The policy adapters target CacheLayer 4.x. Uniqueness, overlap protection,
fixed-window rate limiting, and circuit breaking
adapt CacheLayer's existing lock and atomic-counter contracts. Omnibus does not
introduce a competing cache-provider hierarchy.

Host Runwire context
--------------------

Pass the host's existing runtime, active request, and optional coroutine scope
through frameworks or intermediary libraries into Omnibus:

.. code-block:: php

   $envelope = $bus->withRunwire(
       runtime: $hostRuntime,
       callback: static fn () => $bus->dispatch($message),
       request: $hostRequest,
       scope: $hostScope,
   );

``Consumer`` and ``Worker`` support the same entry point. Set ``$hostScope`` to
``null`` when the host has no coroutine scope. Bind explicitly in each Fiber;
parent-Fiber bindings are not inherited. Nested bindings restore the previous
context when the callback exits, including exceptions. The host retains request
completion, worker supervision and event-loop ownership.

For shared DBLayer composition, pass the same ``RunwireBinding`` instance to
``MessageBus`` and the DBLayer adapters at construction, or register the
existing connection with ``$bus->runwireBinding()->registerConnection($connection)``.
The binding forwards the active runtime, request and scope to registered
connections. CacheLayer policies reuse a matching host-bound CacheLayer context
when available; Omnibus does not initialize an unused cache integration.

Cancellation checkpoints use the supplied request. Cooperative sleeps require
both a live coroutine scope and the runtime's coroutine capability; otherwise
sleep is synchronous and blocking. A host without Runwire uses the ordinary
Omnibus path. See :doc:`upgrading` for cancellation and settlement semantics,
and :doc:`consumer-validation` for ``examples/runwire-forwarding.php``.

Container scopes
----------------

An application container can implement ``ExecutionScope`` to create a fresh job
scope and release it after every handler, including exceptions. That adapter is
optional; ``DirectExecutionScope`` calls the handler directly.

Web and CLI separation
----------------------

Web applications may construct ``AfterResponseDispatcher`` and a runtime
adapter. Worker/CLI applications construct ``Consumer`` and transports. Neither
path requires booting the other. Ordinary FPM/request and non-pool CLI paths do
not require Runwire or process extensions and do not construct a pool backend.
Signal handling is opt-in through ``WorkerOptions(handleSignals: true)`` and
requires PCNTL. The standalone native ``WorkerPool`` requires PCNTL/POSIX;
Runwire 2.x from 2.1.1 is an explicitly selected alternative pool backend.
Passing a host context does not select a pool backend or start a supervisor.

Provider integrations
---------------------

Redis, AMQP, SQS, telemetry, and broadcasting accept small provider contracts or
callbacks. Keep SDK retry, credential, connection-pool, and shutdown policy in
the adapter layer. Validate provider capability at bootstrap when possible.

3.0 consumer verification
-------------------------

Follow :doc:`consumer-validation` to run source and isolated Composer archive
smoke tests. In a host-owned process, forwarding uses an explicitly supplied
Runwire runtime and request; ordinary request/CLI paths remain signal-free and
do not create a Runwire runtime. Reuse the active host-owned connection and
lifecycle contracts rather than constructing a second process supervisor.

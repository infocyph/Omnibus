Upgrading
=========

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

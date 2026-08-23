Upgrading
=========

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

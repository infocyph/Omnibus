Upgrading
=========

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

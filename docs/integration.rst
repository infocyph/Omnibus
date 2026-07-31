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
   $consumer = new Consumer(
       $transport,
       $handlers,
       $retryStrategy,
       $failures,
       $clock,
       $executionScope,
   );

The same constructors work with a hand-written bootstrap, any PSR-compatible
container, or a framework adapter.

DBLayer integration
-------------------

The database adapters use an existing DBLayer ``Connection``. Migrations execute
``QueueSchema`` statements outside Omnibus runtime paths. Dispatch-after-commit
uses ``AfterCommitDispatcher`` and the connection's transaction callbacks.

CacheLayer integration
----------------------

Uniqueness, overlap protection, fixed-window rate limiting, and circuit breaking
adapt CacheLayer's existing lock and atomic-counter contracts. Omnibus does not
introduce a competing cache-provider hierarchy.

Container scopes
----------------

An application container can implement ``ExecutionScope`` to create a fresh job
scope and release it after every handler, including exceptions. That adapter is
optional; ``DirectExecutionScope`` calls the handler directly.

Web and CLI separation
----------------------

Web applications may construct ``AfterResponseDispatcher`` and a runtime
adapter. Worker/CLI applications construct ``Consumer`` and transports. Neither
path requires booting the other.

Provider integrations
---------------------

Redis, AMQP, SQS, telemetry, and broadcasting accept small provider contracts or
callbacks. Keep SDK retry, credential, connection-pool, and shutdown policy in
the adapter layer. Validate provider capability at bootstrap when possible.

Getting started
===============

Installation
------------

Install the standalone library:

.. code-block:: console

   composer require infocyph/omnibus

The core runtime requires UID and the PSR clock and event-dispatcher contracts.
Install an optional integration only when it is selected:

.. code-block:: console

   composer require infocyph/dblayer
   composer require infocyph/cachelayer

The Redis adapter uses the ``RedisClient`` contract. ``CallbackRedisClient`` can
adapt phpredis, Predis, or another client without making that client a core
dependency. AMQP and SQS follow the same provider-boundary design.

Synchronous message bus
-----------------------

.. code-block:: php

   <?php

   use Infocyph\Omnibus\Envelope\HandledStamp;
   use Infocyph\Omnibus\Handler\HandlerMap;
   use Infocyph\Omnibus\MessageBus;
   use Infocyph\Omnibus\Routing\RouteMap;
   use Infocyph\Omnibus\Transport\SyncTransport;
   use Infocyph\Omnibus\Transport\TransportRegistry;

   $handlers = new HandlerMap([
       CreateInvoice::class => static fn (CreateInvoice $message): string =>
           $invoiceService->create($message),
   ]);

   $bus = new MessageBus(
       new RouteMap(),
       new TransportRegistry([
           'sync' => new SyncTransport($handlers),
       ]),
   );

   $result = $bus->dispatch(new CreateInvoice($accountId));
   $invoiceId = $result->last(HandledStamp::class)?->result;

Asynchronous route
------------------

Register the serializer and transport during application bootstrap, then map
only the messages that should be asynchronous:

.. code-block:: php

   use Infocyph\Omnibus\Routing\Route;
   use Infocyph\Omnibus\Routing\RouteMap;

   $routes = new RouteMap([
       CreateInvoice::class => new Route(
           transport: 'redis',
           queue: 'billing',
           delaySeconds: 2.0,
       ),
   ]);

Changing the route does not change business code. Unselected transports,
failure stores, workflow stores, broker SDKs, and listener maps are not
resolved by dispatch.

One consumer call
-----------------

``Consumer::run()`` performs one bounded receive call. A host process can call
it once, loop around it, schedule it, supervise it, or expose it through any
CLI system:

.. code-block:: php

   $result = $consumer->run(
       queue: 'billing',
       limit: 25,
       visibilitySeconds: 60.0,
   );

   printf(
       "received=%d succeeded=%d released=%d failed=%d\n",
       $result->received,
       $result->succeeded,
       $result->released,
       $result->failed,
   );

Omnibus deliberately does not own daemonization, signals, PID files, process
scaling, or subprocess supervision.

Continue with :doc:`recipes` for complete SQLite, Redis, Memcached, workflow,
worker, failure-replay, scheduling, broadcasting, and telemetry compositions.

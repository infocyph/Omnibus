Handler middleware
==================

``HandlerInvoker`` surrounds handler execution with one explicitly ordered,
framework-neutral middleware list. The same invoker can be shared by
``SyncTransport`` and ``Consumer`` so both paths use the same policy stack while
existing handler callable arguments remain compatible.

Defining middleware
-------------------

.. code-block:: php

   use Infocyph\Omnibus\Envelope\Envelope;
   use Infocyph\Omnibus\Handler\HandlerContext;
   use Infocyph\Omnibus\Handler\HandlerMiddleware;

   final readonly class TraceMiddleware implements HandlerMiddleware
   {
       public function process(
           object $message,
           Envelope $envelope,
           HandlerContext $context,
           callable $next,
       ): mixed {
           // Record the start of handler execution.

           try {
               return $next($message, $envelope, $context);
           } finally {
               // Record completion, including exceptional completion.
           }
       }
   }

Middleware receives immutable execution metadata: queue, delivery attempt, and
whether execution is asynchronous. It may call ``$next`` once, transform the
returned value, or return without calling ``$next`` to short-circuit handling.
Declaration order determines nesting; no priorities or discovery are applied.

Composition
-----------

.. code-block:: php

   $handlers = new HandlerMap([
       CreateInvoice::class => $createInvoice,
   ]);
   $invoker = new HandlerInvoker($handlers, [
       $tracing,
       $idempotency,
   ]);

   $sync = new SyncTransport($invoker);
   $consumer = new Consumer(
       $receiver,
       $invoker,
       $retry,
       $failures,
       $clock,
   );

The invoker resolves the handler through ``HandlerMap``. Synchronous handlers
continue receiving ``(message, envelope, queue)``; consumer handlers continue
receiving ``(message, envelope)``. Middleware always receives the normalized
``HandlerContext``.

Consumer semantics
------------------

Middleware runs inside ``ExecutionScope``. A successful short circuit is
acknowledged. A middleware exception follows the same configured retry,
release, failure-store, and rejection behavior as a handler exception.
Acknowledgement occurs only after the complete pipeline succeeds.

Boundaries
----------

Handler middleware does not intercept:

* ``MessageBus`` routing;
* serialization;
* transport send or receive operations;
* ordinary PSR event listeners;
* worker process lifecycle.

Omnibus does not resolve middleware from a container or registry. The host
application constructs middleware objects. In a process-based ``WorkerPool``,
construct middleware that owns PDO, Redis, broker, or other network resources
inside the child worker factory after fork.

Architecture and boundaries
===========================

Owned responsibilities
----------------------

Omnibus owns:

* explicit message routing and handler lookup;
* synchronous PSR-14 application events;
* reservations, acknowledgement, rejection, release, retry, and terminal
  failure;
* safe, versioned envelope serialization;
* queued listeners, chains, batches, scheduled-message factories, and
  broadcasts;
* execution scopes, queue telemetry decorators, and test utilities.

Retained external responsibilities
----------------------------------

Omnibus reuses existing library and application boundaries:

.. list-table::
   :header-rows: 1

   * - Owner
     - Responsibility retained by that owner
   * - DBLayer
     - Connections, transactions, query execution, and migration orchestration.
   * - CacheLayer
     - Cache backends, distributed leases, locks, and atomic counters.
   * - PSR contracts
     - Clock and event-dispatcher interoperability.
   * - Broker adapter
     - Credentials, connection lifecycle, topology, and provider-specific
       delivery semantics.
   * - Host application
     - Dependency construction, configuration, process lifecycle,
       authentication principals, and HTTP after-response behavior.

No filesystem discovery
-----------------------

Route, handler, listener, codec, transport, queued-listener, and
scheduled-message maps are explicit objects. Construct or compile them during
bootstrap or deployment. Request and worker hot paths perform no directory
scan, attribute scan, docblock scan, or reflection discovery.

Lazy optional paths
-------------------

No durable adapter initializes unless the application constructs it.
Synchronous dispatch does not create a database connection, Redis client,
failure store, workflow store, lock provider, broadcaster, or telemetry
exporter.

Application state
-----------------

Messages are plain application objects. Repositories and application services
emit state-change events explicitly after successful operations. Omnibus does
not introduce Active Record hooks, model observers, or hidden database queries.

Persistent workers
------------------

Map lookup caches are bounded by the finite set of message/event classes seen
by one configured application. Per-message state belongs in an
``ExecutionScope`` and must be released on both success and failure. Omnibus
does not keep mutable global container state.

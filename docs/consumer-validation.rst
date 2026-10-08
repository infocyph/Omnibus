Independent consumer validation
===============================

Executable 3.0 examples
-----------------------

These examples are intentionally standalone PHP entry points and do not
depend on the repository's development test autoloader. With development
dependencies installed in this checkout:

.. code-block:: console

   php examples/standalone-consumer.php
   php examples/runwire-forwarding.php

``standalone-consumer.php`` performs a complete in-memory send, bounded
consumer receive/ack, idempotent business-key processing and PSR-14 event
dispatch through a host-managed ``WorkerLifecycle``. It also uses
``FailureManager::retry()`` to claim, replay and remove a simulated terminal
failure before the consumer finishes its business side effect. Its in-memory queue is
a semantic smoke fixture, **not** persistent storage or a multi-process broker.

``runwire-forwarding.php`` uses a passed Runwire ``RuntimeContext`` and
``RequestContext`` for direct dispatch, intermediary forwarding, and a Fiber
that explicitly rebinds context. It also checks request completion, absence of
retained bindings, 100 successive fresh requests on a persistent runtime and
the non-coroutine sleep fallback. Use host-supplied request contexts in real
applications; do not create synthetic request contexts inside a handler.

Packaged consumer
-----------------

The ``security-standards.yml`` GitHub Actions workflow builds ``composer archive``
and installs it in a separate Composer project without development dependencies,
using the
project's own optimized (not authoritative) autoloader. The standalone probe
is then run against **that consumer autoloader**, followed by installation of
selected optional DBLayer 6, CacheLayer 4 and Runwire 2.1.1 integrations.
Development-only paths must not occur in the release archive.

PHP-FPM request
---------------

CI copies ``examples/fpm-request.php`` into that standalone consumer, mounts
it into an official PHP-FPM container and issues a genuine FastCGI request.
The probe dispatches a synchronous handler and validates the HTTP status and
JSON response using **only core** package dependencies, without PCNTL/POSIX
or a global worker supervisor.

Deployment and recovery checklist
---------------------------------

1. Read :doc:`upgrading` and plan a coordinated cutover across all readers
   and writers of durable Omnibus data. Verify 2.6's wrapped-payload boundary.
2. Ensure the host owns request lifetime, worker shutdown and signal policy.
   Pass a live host context explicitly into the Omnibus entry point; release
   the host-owned request in ``finally``.
3. Use a durable backend for cross-process queues; an in-memory transport
   cannot synchronize between FPM processes or worker replicas.
4. Make handlers idempotent by stable business key. Handle ambiguous sends
   through ``FailureManager`` and workflow dispatch-claim reconciliation,
   preserving ``retrying`` and ``sent`` records across retention pruning.
5. Size visibility and other ownership leases from measured p99 execution,
   cleanup and jitter. See :doc:`operations` for the complete recovery
   contract and :doc:`performance` for the separate regression gate.

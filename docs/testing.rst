Testing
=======

Fast application tests
----------------------

Use:

* ``RecordingSender`` to capture dispatched envelopes;
* ``InMemoryTransport`` for reservation lifecycle;
* ``InMemoryFailureStore`` for terminal outcomes;
* a deterministic PSR clock for delay and visibility;
* explicit maps and codecs matching production aliases.

The in-memory transport assigns stable message IDs and attempt stamps, making
tests representative of durable behavior without claiming durability.

Library coverage
----------------

The Omnibus suite covers:

* synchronous results, polymorphic routes, and listener order;
* queued-listener handoff and missing configuration;
* JSON round trips, aliases, malformed structures, depth, stamp, and byte
  limits;
* acknowledgement, retry, terminal failure, poison payload, and settlement
  ambiguity;
* stale receipt rejection and visibility reclaim;
* MySQL, PostgreSQL, and SQLite schema generation;
* live MySQL/PostgreSQL lifecycle when service credentials are available;
* Redis Lua command boundaries and live Redis lifecycle when configured;
* broker capability, malformed delivery, and over-delivery enforcement;
* detached uniqueness, delayed retry lease refresh, overlap, rate limit,
  circuit recovery, and lease loss;
* workflow ordering, cancellation, terminal-state non-regression, and
  idempotent lifecycle events;
* telemetry success and exporter-failure isolation;
* after-commit, after-response, scheduling, and broadcasting boundaries.

Commands
--------

.. code-block:: console

   composer test
   composer ic:ci
   composer benchmark
   composer soak:consumer
   composer soak:durable

``composer ic:tests`` and ``composer ic:ci`` are supplied by PHPForge and are
the authoritative quality suites. The local ``composer test`` alias is a
conventional fast Pest-only entry point.

The remaining local scripts are intentionally package-specific:

* ``composer benchmark`` runs Omnibus's component lifecycle benchmark;
  PHPForge's ``ic:benchmark`` command runs PHPBench subjects instead;
* ``composer soak:consumer`` proves that the process-local queue drains without
  progressive memory growth;
* ``composer soak:durable`` proves that alternating SQLite consumers drain the
  durable queue;
* PHPForge's ``ic:soak:worker`` complements these by monitoring the RSS and
  lifetime of an arbitrary long-running worker command.

CI runs supported PHP versions with lowest and stable dependency resolution,
clean production installation, static analysis, architecture checks, live
database/Redis services, and strict Sphinx documentation.

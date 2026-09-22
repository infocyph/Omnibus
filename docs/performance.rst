Performance
===========

Hot-path design
---------------

Performance comes from explicit construction and bounded work:

* route, handler, listener, codec, transport, and factory maps resolve once and
  cache class lookups;
* dispatch performs no scanning or reflection discovery;
* clocks are read once per logical timestamp calculation;
* in-memory, database, Redis, and broker receives are bounded;
* DBLayer 5 caps dynamic reservation, workflow-claim, and workflow-insert
  batches to the active connection's effective bind budget;
* optional policies and telemetry are decorators, absent from unselected paths;
* durable schemas and maps are prepared before request/consumer work.

Benchmarks
----------

``composer benchmark`` measures component operations:

* synchronous dispatch;
* zero, one, and three event listeners;
* JSON encode/decode and round trip;
* in-memory send/receive/ack;
* failure-store writes;
* consumer terminal retry;
* SQLite enqueue and receive/ack batches;
* workflow creation at 1, 100, and 1000 items;
* workflow claim and terminal transition throughput;
* portable DB payload storage expansion;
* direct ``Consumer`` versus single ``Worker`` execution;
* native and Runwire ``WorkerPool`` startup/process/shutdown overhead at
  concurrency 1 and 2;
* native and Runwire parent idle CPU ratio over a controlled idle window;
* repeated clean worker recycle cost and parent-memory growth.

These are microbenchmarks, not application requests per second. Application
throughput also depends on bootstrap, handlers, storage, network latency,
serialization payloads, contention, and observability exporters.

Worker and pool baselines
-------------------------

``composer benchmark`` runs both the ordinary component benchmark and the
worker/process benchmark. Run only the process portion with:

.. code-block:: console

   composer benchmark:worker-pool

The worker/process JSON deliberately separates ``consumer_direct``,
``single_worker``, native ``WorkerPool`` and explicit Runwire ``WorkerPool``
measurements. Pool rows report wall time plus parent CPU time/ratio so idle-loop
cost is attributable to Omnibus supervision rather than message handling.
Recycle rows report completed cycles and parent-memory growth. The benchmark
uses small process-local queues to measure supervision mechanics; it does not
claim shared-queue throughput.

The DB payload benchmark reports serializer bytes, portable stored bytes and
their expansion ratio. Omnibus's portable text wrapper adds a 16-byte version
prefix plus base64 expansion (approximately one third for larger payloads).
This is the portability cost for preserving arbitrary serializer bytes in the
existing cross-driver text schema.

CI benchmark output is the release baseline. Keep the workflow run referenced
by the release-plan tracker with the dependency lock and PHP version used for
comparison. Do not copy one runner's timing into an application capacity claim.
Foundation should run the same Omnibus benchmark before and after its wrapper
migration to attribute wrapper overhead separately.

Soak tests
----------

``composer soak:consumer`` checks bounded memory and stable process-local depth.
``composer soak:durable`` alternates SQLite consumers and verifies the durable
queue drains without duplicate settlement.
``composer soak:workflow`` repeatedly claims, handles, and finalizes batches
while injecting partial dispatch failure, claim expiry, handled redelivery,
duplicate settlement, and terminal-listener failure. It reports reconciliation
attempts/errors, duplicate handler executions, and terminal regressions.
``composer soak:worker-pool`` runs a longer native/Runwire recycle sample and
records startup/shutdown wall time, idle parent CPU, completed recycle cycles,
and parent-memory growth. The WorkerPool contract tests separately assert that
children are fully reaped after normal stop and restart-budget exhaustion.

The DBLayer 5 baseline removes Omnibus's former outer transaction retry loop.
Contention results should therefore show no transaction callback amplification:
a three-attempt DBLayer transaction executes at most three times, not nine.
This ownership change does not weaken receipt, claim-token, or workflow-state
guards.

Database contention is an operational benchmark: test 2/4/8 consumers against
10k and 100k+ mixed ready/delayed/reserved rows on the intended MySQL, MariaDB,
PostgreSQL, and SQL Server versions. Capture messages/s, reservation latency,
lock waits, duplicate settlements, stale-settlement rejection, and query plans
for ``(queue_name, available_at, reserved_until)`` versus candidate
ready/reclaim indexes. Also test SQLite at the process count intended for the
single-host deployment: DBLayer retries short writer contention, but SQLite
still serializes writes and should not be treated as a server-database
throughput substitute.

Run the harness for each service database, for example:

.. code-block:: console

   composer benchmark:db-contention -- mysql 4 100000
   composer benchmark:db-contention -- pgsql 8 100000 candidate
   composer benchmark:db-contention -- pgsql 8 100000 candidate-reclaim

The optional fourth argument selects the current queue index, the
``(queue_name, available_at, id)`` candidate, or that candidate plus
``(queue_name, reserved_until)``. The harness seeds ready, delayed, actively
reserved, expired-reservation, and old rows. Its JSON records receive calls,
reservation count, total and mean receive latency, per-worker mean, p50/p95/p99,
throughput, settlement anomalies, transaction statistics, and the backend's
``EXPLAIN ANALYZE`` output. Run all three index sets at 10k, 100k, and 1m rows
where practical before changing the production schema.

Regression practice
-------------------

Compare the same PHP version, dependency lock, hardware, warmup, iteration
count, and backend. Treat statistically noisy changes cautiously. Correctness,
security, bounded resource use, and delivery guarantees take priority over a
microbenchmark improvement.

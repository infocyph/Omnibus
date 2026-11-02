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
* DBLayer 6 caps dynamic reservation, workflow-claim, and workflow-insert
  batches to the active connection's effective bind budget;
* optional policies and telemetry are decorators, absent from unselected paths;
* durable schemas and maps are prepared before request/consumer work.

Configured lookup maps memoize already-loaded PHP classes. Evicting map entries
does not unload those classes; retain the direct lookup path unless a real
persistent-host workload demonstrates a need for bounded eviction. Route and
handler maps have different equality and ambiguity contracts, and dispatch
uniqueness and execution overlap have different lease lifetimes. Similar code
across these boundaries does not justify a shared hot-path wrapper.

Message encoder output validation deliberately precedes envelope JSON encoding.
It rejects cycles, invalid UTF-8, excessive depth, resources, objects and
non-finite floats at the trust boundary. Removing this validation requires an
equivalent correct replacement and representative measurements.

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

CI benchmark artifacts retain the source revision, dependency lock and PHP
version used for comparison. Keep those identities with release evidence.
Do not copy one runner's timing into an application capacity claim.
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

For a sustained process-local Runwire request/consumer lifecycle check, run:

.. code-block:: console

   php benchmarks/release-300s-soak.php 300

The script creates a fresh request per operation, checks binding restoration
and queue drainage, and measures post-warmup PHP allocated-memory growth.
It does not certify cross-process RSS or HTTP capacity.

To characterize retention with a large SQLite failure history, run:

.. code-block:: console

   php benchmarks/failure-retention.php 1000000

The script verifies that pruning preserves active retrying and sent claims,
then conditionally reconciles them. SQLite measurements do not establish
retention throughput on a server database. Earlier diagnostic runs covered
100k MySQL/PostgreSQL queues with four consumers and a 1m-row SQLite failure
history without settlement or claim-retention anomalies; those single trials
did not justify changing the production queue indexes.

Since 2.5, Omnibus delegates transaction retries to DBLayer (6.x for Omnibus 3.0) rather than wrapping them again.
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

Release performance comparison
------------------------------

For 3.1, the matched workflow compares the released 3.0.2 baseline, the same
source with the new dependency floors, and unbound, host-context-only and
Runwire-bound candidate variants. Artifacts use version-independent
``baseline``, ``dependency-only`` and ``candidate-*`` labels and retain source
revisions and dependency-lock hashes. The workflow runs on current pull
requests and main pushes, and remains manually dispatchable.

The maintained validator requires seven trials for each of five variants,
matching environment fingerprints and source revisions, four accepting HTTP
workers plus their parent, and live process-tree CPU/RSS sampling. The workflow
uses 10,000 requests per client, producing 40,000 correct warm-c4 responses per
trial. Hosted validation rejects a warm-c4 window shorter than three seconds.
Each trial also includes cold HTTP, warmed concurrency 1/2/4, expected HTTP 503,
and a two-connection 2,000-message SQLite drain with zero remaining depth.

The acceptance limits remain:

* 2% maximum median successful-RPM regression for unbound candidate vs baseline;
* 3% maximum bound-vs-host-only successful-RPM overhead;
* 2% maximum per-variant RPM coefficient of variation;
* 15% p95 and 20% p99 latency regression;
* 5% CPU seconds per successful operation regression;
* 4 MiB process-tree RSS growth per 10,000 correct requests;
* zero incorrect responses, failed operations or timeouts.

The owner approved the scoped 3% binding budget on 2026-10-08 after full HTTP
profiling. The previous candidate's 2.349% binding overhead had failed the
original 2% limit with low variance; that result was an accepted integration
cost, not dismissed as noise. Admission and operation checkpoints both remain
necessary because callbacks can change request completion, cancellation, scope,
PID or generation state. Revisit this capacity decision when the binding,
runtime or representative workload changes. It does not relax the ordinary
unbound 2% budget or justify removing ownership checks for speed.

``php benchmarks/release-acceptance-selftest.php`` verifies both runner modes
and rejects malformed evidence. It accepts 2.5% overhead only for the bound
comparison, rejects 2.5% unbound regression and rejects 3.1% bound overhead.

GitHub-hosted acceptance is shared-infrastructure evidence. The report must
declare ``certification_scope=github-hosted-matched``,
``environment_stable=false`` and ``runner_environment=github-hosted``. Neither
component timings nor hosted acceptance establish dedicated-machine stability
or production capacity. Investigate failed trials and rerun an unchanged
revision before attributing noisy results to source or changing any budget.

Verified 3.1 implementation checkpoint
--------------------------------------

PR #19 head ``097c3ffed2fdd60926267d0955996bd541a2112e`` passed
`Security & Standards run 41430427867
<https://github.com/infocyph/Omnibus/actions/runs/41430427867>`_
and `matched performance run 41430427390
<https://github.com/infocyph/Omnibus/actions/runs/41430427390>`_. The hosted merge
revision ``857f5ae258c72faceecfe06b933d0b8dd70b6dc1`` has the same source tree.
The independently checked performance artifact contains all 35 trials, lock
hashes and policy ``omnibus-3.1-scoped-runwire-2026-11-02``. Unbound successful
RPM was 0.135% higher than 3.0.2; bound overhead was 1.745%. Every configured
latency, CPU, RSS, variance and correctness gate passed.

The QA workflow passed its 15 required jobs: PHP 8.4/8.5 analysis and
lowest/stable dependency lanes; MySQL, MariaDB, PostgreSQL, SQL Server, SQLite,
Redis, Valkey and Memcached integration coverage; writer affinity; benchmark
contract validation; clean production installation; standalone/Runwire
examples; independent archive consumers and actual PHP-FPM; and strict Sphinx.
The tag-publication job and optional security-report job were conditionally
skipped. Runtime publication was not part of these PR checks.

These results validate that implementation checkpoint. Any later cleanup or
release commit requires its own hosted workflows before tagging; merge, tags
and publication remain maintainer-owned.

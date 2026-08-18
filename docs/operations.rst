Consumer operations and telemetry
=================================

Consumer and worker lifecycle
-----------------------------

``ConsumerTask`` accepts one immutable ``ConsumeRequest`` and performs one
bounded consumer call. ``Consumer::run()`` remains the smallest execution
primitive and never creates concurrency itself.

``Worker`` adds a long-running loop around one ``Consumer``. ``WorkerOptions``
controls queue, prefetch, visibility, idle backoff and jitter, optional
message/runtime/absolute-memory/memory-growth recycling limits, and graceful
SIGTERM/SIGINT handling. Prefetch and concurrency are independent: prefetch
bounds one receive call; concurrency is the number of worker processes.

``WorkerPool`` is an optional Unix/Linux fixed-process supervisor. It requires
``ext-pcntl`` and ``ext-posix``. The pool keeps the configured concurrency
stable, replaces cleanly recycled workers, and respawns crashed workers with a
bounded linear backoff. Exhausting the crash restart budget fails the pool and
signals the remaining children to stop. Parent signal handlers are scoped to
``WorkerPool::run()`` and restored before it returns or rethrows.

The worker factory is invoked only after ``fork()``. Create PDO/DBLayer,
Redis/Valkey, AMQP, SQS and other process-bound resources inside that factory.
Do not capture or initialize live network/database resources in the parent and
then fork them into workers. The child also resets the pool's inherited signal
handlers before constructing the worker, so worker-level signal policy starts
from a clean process state.

Example::

    use Infocyph\Omnibus\Consumer\Worker;
    use Infocyph\Omnibus\Consumer\WorkerOptions;
    use Infocyph\Omnibus\Consumer\WorkerPool;

    $pool = new WorkerPool(
        workerFactory: static function (int $slot): Worker {
            // Build DBLayer/Redis/AMQP/SQS connections here, after fork.
            $consumer = buildConsumerForProcess($slot);

            return new Worker($consumer, new WorkerOptions(
                queue: 'default',
                prefetch: 1,
                visibilitySeconds: 60,
                maxMessages: 10_000,
                maxRuntimeSeconds: 3600,
                memoryLimitBytes: 256 * 1024 * 1024,
                maxMemoryGrowthBytes: 64 * 1024 * 1024,
            ));
        },
        concurrency: 4,
        maximumRestarts: 5,
        restartBackoffSeconds: 0.25,
        shutdownGraceSeconds: 30,
    );

    $pool->run();

``maxMessages``, ``maxRuntimeSeconds``, ``memoryLimitBytes`` and
``maxMemoryGrowthBytes`` are per-worker recycling limits. The absolute memory
limit protects the process ceiling; the growth limit detects a worker that
keeps accumulating memory relative to its post-bootstrap baseline. When a
pooled worker exits cleanly because one of these limits is reached, the pool
starts a fresh child for that slot. Use ``Worker`` directly when the process
itself should terminate instead of being recycled by an in-process pool.

Pool shutdown is bounded. SIGTERM or SIGINT asks children to stop cooperatively,
then the parent reaps them until ``shutdownGraceSeconds`` expires. Any remaining
children receive SIGKILL and are reaped before the pool returns. This prevents a
standalone pool from waiting forever on a handler blocked in native database,
network, filesystem, SDK, or extension code. The default grace period is 30
seconds.

External Supervisor, systemd, Docker, Kubernetes or another process manager is
still the preferred production supervisor when available. In that deployment,
run one ``Worker`` per managed process and let the external supervisor own
process count, restart policy, graceful-stop timeout, and hard termination.
``WorkerPool`` exists for standalone PHP runtimes that need built-in fixed
parallelism without another service.

The in-memory transport is process-local and therefore does not become shared
by using ``WorkerPool``. Parallel workers require a durable/shared transport
such as DBLayer, Redis/Valkey, AMQP or SQS.

SQLite parallel consumers require DBLayer 4.0.1 or newer. DBLayer owns SQLite
transaction semantics and reserves writer ownership at transaction start;
Omnibus does not expose a SQLite transaction-mode option. Queue claim and atomic
workflow-settlement transactions use DBLayer retries to absorb short writer
contention. Keep these transactions short and keep handler execution outside
reservation transactions.

Set visibility longer than ordinary handler execution. The cooperative
``DeadlineExecutionScope`` adds ``CancellationStamp`` and checks the deadline
after execution, but cannot interrupt blocking PHP code. ``Worker`` itself
remains cooperative; hard termination belongs to ``WorkerPool`` or the external
process supervisor.

After-response dispatch
-----------------------

``AfterResponseDispatcher`` passes a callback to ``AfterResponseRuntime``. A web
application can adapt its active server/runtime. CLI consumers do not construct
this object and pay no web-runtime initialization cost.

Telemetry
---------

Telemetry is decorator based:

.. list-table::
   :header-rows: 1

   * - Decorator
     - Measurements
   * - ``ObservedTransport``
     - Enqueue/receive duration, counts, attempts, wait/age, retry delay,
       settlement, and depth.
   * - ``ObservedExecutionScope``
     - Handler duration and success/failure.
   * - ``ObservedFailureStore``
     - Terminal failure count and class.

Each observation calls ``TelemetrySink::record()`` with scalar attributes.
Exporter failures are swallowed by the decorators: observability cannot turn a
successful enqueue or settlement into an apparent failure, prevent failure
persistence, or mask the original handler exception.

Do not use message IDs, user IDs, or other unbounded values as metric labels.
DBLayer and Redis report exact visible depth. SQS and some AMQP providers may
report approximate depth; inspect ``BrokerCapabilities::exactSize``.

Failure-store operation
-----------------------

Monitor terminal failure count, oldest failure age, visible queue depth,
attempt distribution, and handler duration. Define retention and pruning in the
application. Back up the durable failure table according to its operational
value and payload sensitivity.

See :ref:`host-owned-worker-loop`, :ref:`failure-inspection-and-replay`, and
:ref:`telemetry-decorators` for complete runtime compositions.

Workflow recovery
-----------------

Do not edit workflow rows while workers run. Expired queue reservations are
reclaimed by normal receive; expired ``dispatching`` claims are reclaimed by
``dispatchPending()``. A ``handled`` item means business code already returned:
restore the queue/store dependency and reconcile settlement without replaying
the handler. Inspect terminal ``failed`` workflows and choose a domain recovery
operation. Generic failure retry refuses chain and batch stamps because a blind
resend could violate the aggregate. Operators must use ``FailureManager``
rather than sending a failure envelope directly. The manager atomically claims
a failure before decoding and sending it. A concurrent retry of the same ID
fails with ``FailureRetryClaimUnavailable``. Failed validation or sending
releases the claim, an abandoned claim becomes available after its lease
expires, and a successful send moves the record to ``sent`` before conditional
removal. A ``sent`` record is never automatically reclaimed, so a removal
failure cannot silently send the message again.

If initial workflow dispatch raises ``WorkflowDispatchFailed``, retain its
``workflowId`` and call ``dispatchPending()`` after the sender recovers. If
settlement raises ``WorkflowPostSettlementFailure``, do not replay the current
handler: its reservation and item are already settled. Recover
``dispatch-next`` by calling ``dispatchPending()`` for the reported workflow.
Poison workflow payloads are correlated through durable message-ID metadata;
inspect the raw failure and recover the terminal workflow as domain policy
requires without attempting to decode stale workflow stamps.

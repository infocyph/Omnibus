Consumer operations and telemetry
=================================

Host-owned process lifecycle
----------------------------

``ConsumerTask`` accepts one immutable ``ConsumeRequest`` and performs one
bounded consumer call. It does not loop, fork, scale, handle signals, write PID
files, supervise subprocesses, or terminate workers.

A long-running host should:

* create application dependencies once;
* create or reset a per-message scope for each handler;
* call the bounded consumer repeatedly;
* stop on its own signal, memory, message-count, or time policy;
* allow visibility timeout to recover work after an unclean exit.

Set visibility longer than ordinary handler execution. The cooperative
``DeadlineExecutionScope`` adds ``CancellationStamp`` and checks the deadline
after execution, but cannot interrupt blocking PHP code. Hard termination
belongs to the host process.

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

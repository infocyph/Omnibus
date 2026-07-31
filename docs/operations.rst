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

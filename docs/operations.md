# Consumer operations and telemetry

`ConsumerTask` accepts one immutable `ConsumeRequest` and performs one bounded
consumer call. It does not loop, fork, scale, handle signals, write PID files,
or supervise subprocesses. Console owns those process controls and will adapt
this one-shot boundary after Omnibus is published.

`AfterResponseDispatcher` hands a callback to `AfterResponseRuntime`. Foundation
implements that runtime for its selected HTTP server. Omnibus does not infer a
web runtime and does not load this path in CLI workers.

Telemetry is also decorator based:

- `ObservedTransport`: enqueue/receive duration, received/enqueued counts,
  attempts, wait time, retry delay, settlements, and depth;
- `ObservedExecutionScope`: processing duration and success/failure;
- `ObservedFailureStore`: terminal failure count and class.

Each observation calls `TelemetrySink::record()` with scalar attributes.
Applications adapt that sink to their metrics/tracing provider. Do not attach
high-cardinality message IDs as metric labels.

DBLayer depth is exact for ready or expired rows. Redis depth counts ready and
expired reservations. SQS and some AMQP providers may report approximate depth;
consult `BrokerCapabilities::exactSize`.

# Testing and performance

`RecordingSender` captures dispatched envelopes without executing them.
`InMemoryTransport`, a deterministic PSR clock, `InMemoryFailureStore`, and
explicit handler/listener maps provide end-to-end consumer tests without a
broker.

The suite covers:

- synchronous handler results and generated IDs;
- polymorphic routing;
- synchronous listener ordering;
- queued-listener handoff;
- safe JSON round trips and unknown aliases;
- successful acknowledgement;
- retry and terminal failure accounting;
- expired visibility redelivery;
- stale-receipt rejection after DBLayer crash recovery;
- poison-payload terminal rejection;
- DBLayer queue, failure, and workflow persistence on SQLite;
- Redis atomic-script command/response boundaries;
- AMQP/SQS capability enforcement;
- unique/overlap leases, rate limiting, circuit recovery, and lease loss;
- cooperative cancellation and host-owned after-response dispatch;
- strict chain order, chain failure-stop, batch progress, and finalization;
- failure retry and opt-in telemetry;
- scheduled message keys;
- broadcast delegation and channel invariants;
- DBLayer outer-commit and rollback behavior using SQLite.

`composer benchmark` measures synchronous dispatch, zero/one listener paths,
JSON encode/decode, in-memory lifecycle, failure-store writes, SQLite durable
enqueue, and SQLite receive/ack batches. `composer soak:consumer` proves bounded
memory and stable process-local depth; `composer soak:durable` alternates two
SQLite consumers and proves the durable queue drains without duplicates.

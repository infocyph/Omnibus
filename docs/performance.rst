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
* SQLite enqueue and receive/ack batches.

These are microbenchmarks, not application requests per second. Application
throughput also depends on bootstrap, handlers, storage, network latency,
serialization payloads, contention, and observability exporters.

Soak tests
----------

``composer soak:consumer`` checks bounded memory and stable process-local depth.
``composer soak:durable`` alternates SQLite consumers and verifies the durable
queue drains without duplicate settlement.

Regression practice
-------------------

Compare the same PHP version, dependency lock, hardware, warmup, iteration
count, and backend. Treat statistically noisy changes cautiously. Correctness,
security, bounded resource use, and delivery guarantees take priority over a
microbenchmark improvement.

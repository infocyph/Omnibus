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
- scheduled message keys;
- broadcast delegation and channel invariants;
- DBLayer outer-commit and rollback behavior using SQLite.

Before release, benchmarks must separately measure zero/one/multiple listener
dispatch, synchronous handling, enqueue-only work, JSON serialization,
reservation batches, retry/failure paths, and sustained consumer throughput.
Soak tests must prove bounded memory and stable queue depth.

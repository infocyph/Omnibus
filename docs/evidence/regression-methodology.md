# Host vs. Omnibus overhead — methodology correction

**Status:** diagnostic investigation; no release-performance gate waived.

## Invalid interpretation corrected

The existing `benchmarks/release.php` workload used `php -S` with one built-in host process. Four child HTTP clients were launched, but the host router was a single-worker server, so successful concurrent client attempts were serialized at execution. The `benchmarks/release-host.php` router also reconstructed the bus and synthetic Runwire runtime inside each script request, rather than receiving one persistent host-owned `RuntimeContext`. These historical results remain attached to the review as diagnostic measurements, not a calibrated production-equivalent concurrency-4 host throughput comparison.

**Change:** `benchmarks/runwire-attribution.php` creates a single persistent runtime and bus at process startup, then measures 3 rotated trials of five scenarios: direct dispatch, request creation only, request + unbound dispatch, request + bound no-op, and fresh request + bound dispatch. A new `RequestContext` is created/completed on every operation. The harness validates return values, confirms context cleanup, collects per-operation p50/p95/p99, process CPU times and PHP allocated-memory growth. GitHub-hosted data is marked `stable=false`. It cannot establish real HTTP capacity, multi-process RSS, or the 2% release budget.

Next: review actual attribution output, then benchmark a stable host and durable workload with real concurrency, warmup, requests/jobs success validation, sustained CPU/RSS, and comparable PHP/dependency locks. Only optimize native Omnibus code when that attribution supports a meaningful, correct change.

# Host vs. Omnibus overhead — methodology correction

**Status:** diagnostic investigation; no release-performance gate waived.

## Invalid interpretation corrected

The existing `benchmarks/release.php` workload used `php -S` with one built-in host process. Four child HTTP clients were launched, but the host router was a single-worker server, so successful concurrent client attempts were serialized at execution. The `benchmarks/release-host.php` router also reconstructed the bus and synthetic Runwire runtime inside each script request, rather than receiving one persistent host-owned `RuntimeContext`. These historical results remain attached to the review as diagnostic measurements, not a calibrated production-equivalent concurrency-4 host throughput comparison.

**Change:** `benchmarks/runwire-attribution.php` creates a single persistent runtime and bus at process startup, then measures 3 rotated trials of five scenarios: direct dispatch, request creation only, request + unbound dispatch, request + bound no-op, and fresh request + bound dispatch. A new `RequestContext` is created/completed on every operation. The harness validates return values, confirms context cleanup, collects per-operation p50/p95/p99, process CPU times and PHP allocated-memory growth. GitHub-hosted data is marked `stable=false`. It cannot establish real HTTP capacity, multi-process RSS, or the 2% release budget.

Next: review actual attribution output, then benchmark a stable host and durable workload with real concurrency, warmup, requests/jobs success validation, sustained CPU/RSS, and comparable PHP/dependency locks. Only optimize native Omnibus code when that attribution supports a meaningful, correct change.

## Same-dependency fast-path A/B (2026-10-08)

In [run 37731608342](https://github.com/infocyph/Omnibus/actions/runs/37731608342), the only switched PHP source file between original and candidate was `src/Integration/Runwire/RunwireBinding.php`, with an identical resolved Composer stack, alternating the six source variants across the same runner. Median `bound-dispatch` throughput over nine sample windows per variant:

| PHP | Original ops/s | Candidate ops/s | Gain |
| --- | ---: | ---: | ---: |
| 8.4 | 63,484 | 65,146 | +2.6% |
| 8.5 | 84,366 | 86,374 | +2.4% |

The accepted candidate short-circuits empty adapter-wrapping **after** validating runtime/request/scope. It retains full adapter integration where a CacheLayer binding or DBLayer connection is present, and preserves the old callback exception/return behavior. Component data does not prove a host-level RPM improvement; the separate PHPForge service QA remains mandatory. Do not claim this meets the stable 2% release budget.

## Benchmark metadata correction

The old candidate `benchmarks/release.php` carried the immutable 2.6 `AUDITED_REVISION` into 3.0 outputs, resulting in invalid provenance. The harness now requires the invoker to label the actual source revision and release variant through `OMNIBUS_BENCHMARK_REVISION` and `OMNIBUS_BENCHMARK_RELEASE`; missing values are explicitly `unlabeled`. The matched-version workflow supplies the actual checkout SHA and variant labels. That earlier HTTP workload declared one built-in PHP host worker despite four client processes. The current harness uses four accepting PHP workers in matched release CI, records the configured worker count and implementation in each HTTP workload, and samples the live process tree. These fields distinguish client concurrency from host concurrency and prevent mistaking the candidate for 2.6 source.

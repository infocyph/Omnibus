# Batch 8 nonperformance preflight and held release gate

Historical preflight: the open gates below describe this earlier revision. See the [release verification record](omnibus-3.0.0-release-notes-draft.md#verification-and-publication-status) for the subsequently passed QA and matched performance acceptance.

Date: 2026-10-08 (Asia/Dhaka)

## Exact-SHA evidence

- Source/QA SHA: `7c953fa3b32a8fdb5feb84ab244214144ebb50e9`.
- [Security & Standards run 37730128164](https://github.com/infocyph/Omnibus/actions/runs/37730128164): PHP 8.4/8.5 analysis, four service-backed prefer-lowest/stable QA lanes, clean install, both representative benchmark smoke jobs, and lagging-replica writer-affinity **passed**. The integration matrix selected MySQL, MariaDB, PostgreSQL, SQL Server, SQLite, Redis, Valkey and Memcached; case counts by individual backend are not exported and remain an evidence action.
- [Batch 7 packaged consumer / documentation run 37730122689](https://github.com/infocyph/Omnibus/actions/runs/37730122689): standalone and forwarding smoke, real FPM FastCGI 200 response, independent Composer archive production consumer and optional integration installation, strict Sphinx **passed**.
- [Release preflight run 37730122676](https://github.com/infocyph/Omnibus/actions/runs/37730122676): `composer validate --strict`, production and all-lock live audit, `composer ic:process`, `composer ic:tests:details`, `composer ic:release:guard`, and clean git diff **passed**. Detailed Pest run: **232 passed, 1,247 assertions**. PHPForge reported 11 duplicate groups / 5.41% with status PASS, no static errors, no suppressed rules.
- Audit: **0 vulnerability advisories** and **1 reported abandoned transitive dev package** (`doctrine/annotations`). The abandoned package is not suppressed: its status is recorded as a dependency ecosystem warning while security advisories remain fatal.
- [300-second soak run 37730037465](https://github.com/infocyph/Omnibus/actions/runs/37730037465) on prior implementation SHA `4e72b441eef5891a1d4591ce464175be4131ef27`: **passed** 300.000 seconds, completed **6,838,272** in-memory queue jobs through a persistent Runwire-bound CLI host, 32 idempotent business keys, final queue depth 0, measured PHP allocated-memory growth 0 after warmup, sampled PHP allocated peak 8 MiB. Script remained unchanged between that SHA and `7c953fa3`. This is process-local functional/resource evidence, NOT multi-process worker-tree RSS or production queue/RPM certification.

## Deferred by owner — mandatory before tagging

1. Stable production-equivalent matched workload comparison of released 2.6, dependency-only, 3.0 unbound and 3.0 host-bound, with a maximum **2% median successful-request RPM** regression; capture measured p95/p99, failures/timeouts, CPU, live child RSS and memory over time, and warm/cold and retry/contention paths.
2. 100k/1m queue-backlog/history/retention and driver-specific index/lock-contention evidence where deployment-relevant, plus live multi-process host lifecycle and full process-tree resource accounting. Do not make unmeasured schema/index changes.
3. Export actual registered per-backend test-case counts. Verify final dependency-lock hashes and release candidate SHA after all source/documentation changes.
4. Owner review and explicit publication permission. Preserve released `2.6` tag; **PR #14 stays draft**, with no merge, new tag or publishing.

## Disposition

All currently accepted production changes keep the native DBLayer/CacheLayer/Runwire boundaries, conditional ownership, durable at-least-once idempotency and unchanged PHPForge rules. Nonperformance engineering work is complete. The 3.0.0 **release gate remains open** for the listed items.

## Deep backend and multi-worker evidence added in final audit

See `docs/evidence/batch-8-deep-benchmark.md` for 100k MySQL/PostgreSQL contention, SQLite 1m failure-history pruning, backend case discovery counts, five-process host CPU/RSS, p95/p99 and the measured 2.6 ↔ 3.0 variants. This historical preflight used the original dedicated-runner/2% contract. The owner subsequently selected GitHub-hosted matched acceptance and, after full HTTP profiling on 2026-10-08, approved the scoped bound cost trade-off: 2% unbound regression, 3% bound-vs-host-only overhead, other budgets unchanged. The batch workflows are retired and their distinct consumer/docs/validator checks consolidated into the two maintained workflows. Current evidence and remaining gates are recorded in [binding overhead](runwire-binding-overhead.md) and the [release verification record](omnibus-3.0.0-release-notes-draft.md#verification-and-publication-status). Historical results above do not certify the updated candidate.

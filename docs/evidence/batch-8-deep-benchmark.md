# Batch 8 deep storage, backend case coverage and four-worker host diagnostics

Historical status at this diagnostic revision: **functional, deep storage, and shared-runner suites passed; matched release performance acceptance was still open** (2026-10-08). Subsequent QA and matched acceptance passed; see the [release verification record](omnibus-3.0.0-release-notes-draft.md#verification-and-publication-status).

## Registered integration tests — actual discovery

[Discovery workflow #37733839219](https://github.com/infocyph/Omnibus/actions/runs/37733839219) registered 75 integration test instances and emitted the `backend-counts.json` and raw `--list-tests` artifact. Case identifiers/datasets matching each backend:

| Backend | Registered matching cases |
| --- | ---: |
| MySQL | 6 |
| MariaDB | 6 |
| PostgreSQL (`pgsql`) | 6 |
| SQL Server (`mssql`) | 6 |
| SQLite | 6 |
| Redis | 8 |
| Valkey | 4 |
| Memcached | 1 |

These are *registered test identifier matches* and may overlap across backend names; they must not be summed or construed as eight independent executed suites. The strict full PHPForge QA separately selects all eight backend service groups with skipped-test failures enforced.

## Deep queue contention: MySQL and PostgreSQL

[100k-depth workflow #37733678625](https://github.com/infocyph/Omnibus/actions/runs/37733678625) passed with four forked consumers, real SQL query plans, two candidate index layouts, **zero** missing/duplicate/stale settlements in every run. Measured on GitHub-hosted runners:

| Engine | Index | Completed messages/s | Receive p99, ms |
| --- | --- | ---: | ---: |
| MySQL 8.4 | Production | 1,680.57 | 85.449 |
| MySQL 8.4 | `(queue_name, available_at, id)` candidate | 2,831.45 | 11.845 |
| PostgreSQL 18 | Production | 4,046.83 | 108.019 |
| PostgreSQL 18 | Candidate | 4,902.56 | 110.296 |

**Disposition:** Retain the current production queue index. These encouraging hosted single-trial figures do not justify automatically changing a deployed schema/index: no repeat dispersion estimates, MariaDB/SQL Server migration/rollback validation, or live claim/recovery tail evidence are supplied for the candidate layout. No hidden schema migration or unsafe alternate reservation query was introduced.

## Large failure history and active retry ownership

[Retention workflow #37734176837](https://github.com/infocyph/Omnibus/actions/runs/37734176837) passed SQLite `100,000` and `1,000,000` aged failure entries. Pruning removed exactly 99,998 and 999,998 unclaimed failed entries respectively and **preserved the two active retrying/sent claim rows**. After conditional retry release and sent-state removal, final failed-row count was zero. This validates the corrected retention ownership invariant at realistic history sizes without claiming identical production SQL engine performance.

## Real multi-process host request metrics

[Four-worker comparison #37734388224](https://github.com/infocyph/Omnibus/actions/runs/37734388224) used four accepting `php -S` workers (five observed processes including supervisor), an identical benchmark harness for released 2.6 and 3.0, three alternating same-runner trials per variant, and **4,000 correct responses per concurrency-four trial**. All trials had zero failed operations or timeouts. The sampler recorded Linux per-process CPU time and full process-tree RSS every 20ms.

| Variant | Median successful RPM | p95 / p99 ms | Median CPU µs / successful op | Median peak RSS MiB |
| --- | ---: | ---: | ---: | ---: |
| Released 2.6 | 292,563 | 1.209 / 1.515 | 429.00 | 181.8 |
| 2.6 + 3.0 dependencies | 299,122 | 1.146 / 1.462 | 432.93 | 184.8 |
| 3.0 unbound | 290,105 | 1.181 / 1.542 | 436.38 | 182.4 |
| 3.0 host-context-only | 271,605 | 1.259 / 1.640 | 499.17 | 184.6 |
| 3.0 Runwire-bound | 266,919 | 1.273 / 1.661 | 513.53 | 188.3 |

For the comparable paths, the observed median unbound 3.0 RPM reduction from released 2.6 was **0.84%**; bound 3.0 versus current host-context-only was **1.73%**. The maximum per-variant RPM coefficient of variation was **1.8%** (released 2.6); other variants were under 1%. Sampled process-tree RSS growth over 4,000 requests was roughly 0.14 MiB per scenario, below the 4 MiB per 10k sustained growth budget *for these short trials*. Tail p95/p99 and CPU seconds per successful operation also appear within the recorded respective 15%, 20% and 5% budgets for the two comparable paths.

**Critical qualifier:** This is real multi-process HTTP but **not** a dedicated, isolated stable production Runwire host. Its `environment.stable` was `false`, and the router still constructs request-local PHP userland state. Therefore these metrics are **diagnostic** and do not close the mandatory 2% release gate.

## Original acceptance contract and current policy

The repository now includes `benchmarks/release-acceptance.php` and `.github/workflows/stable-performance.yml`. The original contract required a dedicated self-hosted Linux runner labeled `omnibus-stable`; the owner later selected same-job GitHub-hosted comparison. The active workflow requires seven alternating correct-response trials per variant, a common environment fingerprint and source revision, complete CPU/RSS/p95/p99 metrics, and exports `environment_stable=false`. After profiling and the owner-approved cost trade-off on 2026-10-08, RPM limits are 2% for unbound-vs-2.6 and 3% for bound-vs-host-only; other limits remain unchanged. See [current binding evidence](runwire-binding-overhead.md).

Final source/lock SHA and exact-head PHPForge/consumer audit must be captured after the last changes; no merge, tag or publication until the current matched gate passes for the final candidate and the owner authorizes publication.

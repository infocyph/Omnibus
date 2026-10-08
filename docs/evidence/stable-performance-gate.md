# GitHub-hosted matched release performance acceptance

**Runner change (2026-10-08):** The release benchmark now uses `ubuntu-latest`, with automatic execution on pushes to `feature/runwire-2.1` and optional manual dispatch. No `omnibus-stable` self-hosted runner is required for the **revised GitHub-hosted acceptance gate**. The `stable-performance.yml` filename remains unchanged to preserve the existing workflow URL.

The single job checks out the published `2.6` baseline, a dependency-only upgrade based on `2.6`, and the current `3.0` candidate. It installs independent Composer dependencies with PHP 8.4 within the run, records every lockfile hash and the candidate SHA, then executes seven alternating matched trials for five variants, with four PHP HTTP workers and live process-tree CPU/RSS sampling. All variants and trials execute on **the same GitHub-hosted runner job** to reduce between-machine bias. It retains artifacts even when the acceptance step fails.

The benchmark explicitly sets `OMNIBUS_BENCHMARK_STABLE=0` and captures GitHub's actual `RUNNER_ENVIRONMENT`. The validator runs as `php benchmarks/release-acceptance.php results hosted` and rejects trials claiming dedicated-runner stability or a different runner type. Its report contains `certification_scope=github-hosted-matched`, `environment_stable=false`, and `runner_environment=github-hosted`. The previous dedicated-mode validator remains available for comparison, but the workflow does not pretend hosted evidence comes from isolated hardware.

## Unchanged strict acceptance budgets

Maximum median successful warm-c4 HTTP RPM regression **2%** (released 2.6 versus unbound 3.0, and 3.0 host-only versus Runwire-bound 3.0); RPM coefficient of variation per variant **2%**; p95 regression **15%**; p99 **20%**; CPU seconds per successful operation **5%**; and measured process-tree RSS growth **4 MiB per 10,000 correct requests**. The runner change neither modifies nor suppresses these limits.

The validator still requires all seven trials per variant, a common host fingerprint, consistent labeled source revisions, no incorrect results or timeouts, at least 4,000 correct warm-c4 requests, five observed server processes, and measured CPU/RSS. Every trial must include exactly the cold request, warm concurrency 1/2/4, expected HTTP 503, and two-connection 2,000-message SQLite drain with zero remaining queue depth. A passing job must report `passed=true` and `candidate_revision` identical to the checkout SHA.

## Interpretation, limits and reproducibility

**GitHub-hosted VMs are not dedicated stable hardware.** Seven alternating trials on one VM reduce but cannot eliminate contention, scheduling noise, and unpredictable instance characteristics. A passing job is a **matched hosted-runner release acceptance**, *not* dedicated-performance certification or a claim of repeatable production throughput within 2% everywhere. A failed or high-variability result must not be hidden, rebranded or waived. Inspect the per-variant CV and trial artifacts before release.

The owner explicitly selected this revised hosted CI criterion in place of the previously unavailable self-hosted runner. Historical queued self-hosted jobs and deep diagnostic results remain recorded but are no longer the current runner prerequisite. Keep PHPForge's strict service matrix, the independent consumer/packaging gates, and the regular Batch 8 preflight. The release is not approved until every **current-head** CI gate, including the full hosted matched-performance gate, passes and publication is explicitly authorized.

`php benchmarks/release-acceptance-selftest.php` tests both runner modes, rejects wrongly labeled runner evidence and malformed workloads, and verifies the 2% RPM guard still fails a regression. Synthetic fixtures never count as a benchmark run.

# Omnibus 3.1 audit and release plan

Date: 2026-11-02. Baseline: `40af1cf` (after 3.0.2).

Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` and
PHPForge's agent workflow. Preserve public APIs, durable storage formats,
host-owned runtimes, cancellation, Fiber isolation, and optional integrations.
Do not weaken detectors, add suppressions, edit vendor source, or relax
performance budgets. Merge, tag, and publication remain maintainer-owned.

## Required work

- [x] Raise tested dependency floors to UID `^6.0.1`, CacheLayer `^4.0.2`,
  DBLayer `^6.1.4`, and Runwire `^3.0`.
- [x] Align maintained documentation, capability errors, package consumer CI,
  and dependency assertions. Preserve historical evidence as historical.
- [x] Verify direct/intermediary/Fiber forwarding, cancelled/completed/stale
  contexts, synchronous fallback, and explicit standalone pool ownership
  against published Runwire 3.0.
- [x] Audit security boundaries, deferred dependency resolution, durable
  ownership and recovery, dependencies, packaging, examples, and quality gates.
  Add fixes only for reproduced defects.
- [x] Run PHPForge processors, detailed checks, and final release guard;
  strict Sphinx, archive inventory, and isolated no-dev consumer checks.
- [x] Run component benchmarks, durable/consumer/workflow/pool soaks, and the
  representative HTTP benchmark. Keep smoke evidence distinct from matched
  performance acceptance.
- [x] Resolve the reproduced DBLayer SQLite retry/statement-lifetime defect
  upstream, publish the dependency fix, update the floor, and rerun both
  exception-argument settings. See [audit evidence](../evidence/omnibus-3.1-audit.md).
  Published DBLayer 6.1.4 is installed, the floor is updated, and normal
  Composer autoloading passes on PHP 8.4/8.5 with exception arguments retained.
- [ ] Validate the final committed revision in hosted PHP 8.4/8.5,
  lowest/stable dependencies, all service integrations, real PHP-FPM, and
  matched performance lanes before tagging.

## Findings and decisions

- Runwire 3.0 is published (Composer metadata verified live). Existing core
  APIs should remain unchanged; optional Runwire support moves from 2.x to
  3.x. Recommend **3.1.0**, because the supported optional dependency major
  changes; no major Omnibus release or durable schema migration is planned.
- Packed-consumer CI now installs Runwire `^3.0`, CacheLayer `^4.0.2`, and
  DBLayer `^6.1.4`.
- The matched-performance workflow is historical 2.6-to-3.0 certification and
  previously only auto-ran on `feature/runwire-2.1`. Updated the baseline to
  3.0.2 and triggers to current PRs/main, preserving all numerical budgets.
- Initial live Composer audit: no vulnerability advisories; abandoned
  `doctrine/annotations` is a development-only transitive dependency of
  `phpbench/phpbench 1.7.0`. Track upstream tooling remediation separately
  from runtime vulnerabilities; retain the existing audit policy.
- Host doctor reports missing `pdo_sqlsrv`. Unconfigured remote SQL/cache
  services and any unavailable runtime lanes must be reported explicitly.

## Verification record

### Published DBLayer 6.1.4 follow-up

Composer resolved release commit `c530bf861d2921bc7c59b4ea9ffe4f9dc0620877`.
The full suite passes 254 tests / 1,335 assertions on PHP 8.4.26 and PHP 8.5.4
with `zend.exception_ignore_args=0`, using the installed release and normal
Composer autoloading. The standalone reproduction reports affected rows `1`,
then `0`. This closes the dependency defect; no source override is needed.

Rebuilt archive: `/tmp/omnibus-3.1-dbl614-audit.zip`, 170 entries, all 163 tracked
runtime files present, no forbidden development/generated payloads. SHA-256:
`0cc52503e1cddbe256701fcda54847818223961d26922e5fc389c4ddd2215086`.
Follow-up logs: `/tmp/omnibus-dbl614-*`.

| Follow-up check | Result |
| --- | --- |
| PHPForge processing, detailed suite, final release guard | Passed; 254 tests / 1,335 assertions; zero known advisories |
| PHP 8.4/8.5 with exception arguments retained | 254 tests / 1,335 assertions passed on each runtime |
| Original stale-delete probe | First delete 1 row, second delete 0 rows |
| Rebuilt archive and isolated no-dev consumer | Inventory, platform requirements, standalone and Runwire forwarding passed with released DBLayer 6.1.4 |
| Strict Sphinx and performance-validator self-test | Passed |
| Durable and workflow soaks | 10,000 messages/items each; zero queue remainder or reconciliation errors |

No Omnibus commit/tag/push/publication was made. Exact-final-commit hosted QA
and matched performance remain open.

### Initial audit and pre-publication evidence

The following table records the earlier audit with UID 6.0.1, CacheLayer 4.0.2,
DBLayer 6.1.3, and Runwire 3.0, including the failure that led to the upstream
6.1.4 fix. These historical results do not replace the final revision's gates.

| Check | Result |
| --- | --- |
| Baseline host `composer ic:test:code` | 254 tests / 1,335 assertions passed |
| `composer ic:process`, `composer ic:tests:details` | Passed on host with process/socket access |
| Final `composer ic:release:guard` | Passed; 254 tests / 1,335 assertions; all configured detectors active |
| Live suite, strict seven-service manifest | 281 tests / 1,537 assertions passed: MySQL, MariaDB, PostgreSQL, SQLite, Redis, Valkey, Memcached |
| Dedicated stale-replica affinity lane | 3 tests / 6 assertions passed |
| PHP 8.4.26 / SQLite 3.53.4, exception arguments hidden | 254 tests / 1,335 assertions passed |
| PHP 8.4.26 / SQLite 3.40.1 and PHP 8.5.11 / SQLite 3.53.4, exception arguments retained | 251 passed / 3 failed; upstream DBLayer release blocker, reproduced independently |
| Fixed DBLayer source, PHP 8.4.26 and PHP 8.5.4, exception arguments retained | Omnibus: 254 tests / 1,335 assertions passed on each runtime; temporary source bootstrap, no vendor edits |
| `release-acceptance-selftest.php` | Passed; unchanged numerical acceptance limits |
| Component and representative HTTP benchmarks | Passed; six HTTP/durable workloads; PHPForge result-contract validation passed; no matched-performance claim |
| Consumer/durable/workflow soaks | 10,000 messages/items each; zero queue remainder or workflow errors |
| Native and Runwire pool soak | 100 recycle cycles each; zero allocated-memory growth |
| `php benchmarks/release-300s-soak.php 300` | 300 seconds, 17,611,750 jobs, zero final queue depth or post-warmup allocated-memory growth |
| Strict Sphinx `-n -W` | Passed |
| Composer archive inventory | 170 entries; all 163 tracked runtime files present; no development/generated payloads |
| Isolated `--no-dev` consumer | Platform check, standalone example, and selected optional forwarding passed |
| Real PHP 8.4 FPM / FastCGI | Returned `{"ok":true,"value":42}` from packaged consumer |
| Minimal PHP runtime with PCNTL/POSIX genuinely absent | `portable-core-ok` |

Archive SHA-256:
`2401901944d2eb2a78e9fe60e7a1eb0a84df916d8ddc63ceac15f0a33d11fe07`.
Archive: `/tmp/omnibus-3.1-audit.zip`; command logs and JSON results:
`/tmp/omnibus-*`. Documentation/plans/benchmarks are excluded from distribution.

The normal host has `zend.exception_ignore_args=1`; the initial green guard did
not cover the then-failing setting. The published 6.1.4 follow-up above does.
SQL Server is unverified because `pdo_sqlsrv` is
missing. Lowest/stable hosted dependency lanes, exact-final-SHA service/FPM CI,
and seven-trial hosted performance acceptance still require a committed
candidate. Local direct dependency floors were
tested at the requested releases, not a complete transitive prefer-lowest solve.

Environment notes: sandboxed process signaling stalled the first baseline
attempt; the host retry passed. Sandboxed Rector could not bind its local
coordinator socket. One final host guard attempt encountered an ephemeral
`.aws` directory disappearing during traversal; an unchanged-tree retry with
no concurrent sandbox command passed. No detector configuration was changed.

## Optional improvements

No speculative architecture, bulk restructuring, or performance tuning is
proposed without a concrete finding and representative measurements.

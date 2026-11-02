# Omnibus 3.1 audit evidence

Audit date: 2026-11-02. Starting revision: `40af1cf` (after 3.0.2).
This is working-tree evidence, not final-commit hosted certification.

## Scope and dependency state

Reviewed the security-sensitive serialization/codec boundaries, SQL identifier
validation and bound queries, Redis Lua reservation ownership, broker limits,
failure replay and workflow claims, coordination leases, deferred integrations,
Runwire cancellation/Fiber/generation boundaries, and process lifecycle.
Repository-wide PHPForge checks cover the complete configured source scope.

Initial audit versions: UID 6.0.1, CacheLayer 4.0.2, DBLayer 6.1.3,
Runwire 3.0. The runtime dependency remains UID; the other three integrations
remain optional. Composer lock is ignored by this library and is local evidence.

## Resolved upstream finding: DBLayer retry statement lifetime

**Published resolution:** DBLayer 6.1.4 at
`c530bf861d2921bc7c59b4ea9ffe4f9dc0620877` is installed. Omnibus now requires
`^6.1.4` in its development/integration constraints and consumer CI. The full
suite passes 254 tests / 1,335 assertions on both PHP 8.4 and 8.5 with exception
arguments retained, using normal Composer autoloading. The original probe now
reports affected rows `1`, then `0`. The dependency blocker is closed.

PHPForge processing, the detailed suite, and the final release guard pass.
The rebuilt archive contains all 163 tracked runtime files and excludes
development/generated payloads. An isolated no-dev consumer installs released
6.1.4 and passes standalone execution, Runwire forwarding, and platform checks.
Strict documentation, the performance-validator self-test, and 10,000-item
durable/workflow soaks also pass. See the plan for archive identity and logs.

Before publication, the DBLayer source checkout was verified to reset failed prepared statements
before retry/rollback and preserves the primary execution exception if cleanup
also fails. Twelve regression cases cover both statement-cache and exception
argument settings. The original standalone probe now reports affected rows `1`,
then `0`. Omnibus passes all 254 tests / 1,335 assertions on PHP 8.4 and 8.5
with exception arguments retained and the fixed DBLayer source loaded through
a temporary bootstrap. No vendor source was edited. Those source-only results
are preserved here as historical evidence; release-package checks above now
replace the temporary bootstrap.

DBLayer verification: the final local release guard passes, and the full suite
passes 916 tests / 4,749 assertions on PHP 8.5 and on PHP 8.4 with exception
arguments retained. MySQL, MariaDB, PostgreSQL, and SQLite were available;
SQL Server was not. Strict documentation and representative PostgreSQL workload
smoke checks at concurrency 1/2/4 pass. All four hosted workflows for DBLayer
PR #39 subsequently passed on unchanged head `4b4c58f`, including matched
performance acceptance and the 300-second Runwire soak. DBLayer 6.1.4 is now
published; Omnibus still needs its own final-commit hosted verification.

**Historical defect in 6.1.3.** With `zend.exception_ignore_args=0`, the SQLite retry
path can retain failed statements through exception arguments. A standalone
DBLayer-only probe (no Omnibus classes) observed this sequence after another
connection released a write lock:

```text
DELETE FROM probe WHERE id = ?  -> 1 affected row
DELETE FROM probe WHERE id = ?  -> 1 affected row (expected 0)
```

The equivalent run with `zend.exception_ignore_args=1` returns `1`, then `0`.
Omnibus depends on the affected-row count to reject stale receipts; an
incorrect count defeats that signal. A separate transaction retry test fails
with `cannot commit transaction - SQL statements in progress`.

Reproduced on PHP 8.4.26 / SQLite 3.40.1 and PHP 8.5.4 / SQLite 3.46.1.
The full PHP 8.5.11 / SQLite 3.53.4 container suite also reproduces the same
three integration failures. This is not specific to old SQLite or Runwire 3.

Reproduce the existing adversarial integration cases without changing tests:

```console
php -d zend.exception_ignore_args=0 vendor/bin/pest \
  tests/Integration/DBLayerTransportTest.php \
  --configuration vendor/infocyph/phpforge/resources/pest.xml \
  --bootstrap vendor/autoload.php \
  --filter 'DBLayer alone bounds|receipt-guarded query'
```

On 6.1.3, the three cases failed with exception arguments retained and passed
with them hidden. They now pass against 6.1.4 without changing their assertions.
The fix belongs to DBLayer's execution/retry owner, with regression coverage for
both exception settings. No detector, production configuration, or vendor source
was changed to conceal the failure, and Omnibus does not duplicate DBLayer's
execution machinery.

## Fixed local findings

- Dependency constraints, maintained docs, errors, and isolated-consumer CI
  now select the requested generations and floors.
- The matched-performance workflow previously auto-ran only on the retired
  feature branch and compared against 2.6. It now targets current PRs/main,
  compares against 3.0.2, and uses consistent `baseline`/`candidate-*` labels.
  Seven trials, 2% unbound RPM, the previously approved 3% binding overhead,
  variance, latency, CPU, RSS, and correctness budgets remain unchanged.
- `benchmarks/workflow-soak.php` attempted recovery immediately after a failed
  send, although the attempted dispatch claim remains ambiguous until expiry.
  The frozen clock now advances beyond the 30-second claim before recovery.
  The same 100-workflow/10,000-item scenario passes with zero reconciliation
  errors, duplicate handler executions, and terminal regressions. Diagnostic
  failures now identify all three counters.

## Advisory and maintenance findings

Live Composer audit reports zero vulnerability advisories. It reports one
abandoned development package: `doctrine/annotations`, required by PHPBench
1.7.0 through PHPForge. PHPForge's existing audit policy treats abandonment as
non-blocking. Production consumer installation contains no development tooling.
Resolve that maintenance item upstream; no audit policy was weakened here.

The duplicate detector reports 11 non-blocking clone candidates (about 5%).
These include SQL driver variants, state-transition checks, structurally
similar route/handler maps, lease policies, and benchmark phases. Their owners
have distinct contracts and lifetimes; no broad consolidation is justified
as part of this dependency update. No new production type was added.

## Verification and limits

See the linked release plan for the final command outcomes and open gates.
Local benchmark smoke/soak results do not prove matched release-performance
acceptance. Historical evidence files retain their original dependency and
revision identities. A clean host suite with exception argument redaction
does not certify the failing configuration described above.

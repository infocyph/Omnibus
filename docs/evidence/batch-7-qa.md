# Batch 7 migration and consumer acceptance

Implementation reference SHA: `dd53f0f07d906415b94b95513fadac5fed31c272` plus the following exact-Pint cleanup. All public and source-level changes remain on PR #14.

Dedicated GitHub Actions run: `37729844673` (passed all five jobs, including one diagnostic-only Pint job removed afterward). Earlier complete isolated consumer run `37729445192` passed the same four required categories.

## Verified

- PHP 8.4 and 8.5 run `examples/standalone-consumer.php` and `examples/runwire-forwarding.php` with real Composer dependencies.
- The standalone example exercises a custom PSR-20 clock, PSR-14 event contract, host-managed consumer lifecycle, repeated business-key idempotency, and `FailureManager` claim/replay recovery.
- Runwire sample exercises direct and intermediary forwarding, a bound Fiber, non-coroutine fallback and 100 fresh requests through one runtime, asserting no retained completed request binding.
- `composer archive` excludes development tests, docs, benchmarks, examples, CI, vendor and lockfile.
- A separate production Composer project installs that archived package with `--no-dev` and its **own** optimized non-authoritative autoloader. The standalone smoke passes against the consumer vendor tree.
- A real PHP 8.4-FPM container serves `examples/fpm-request.php` via FastCGI: HTTP 200; body `{"ok":true,"value":42}`.
- The production consumer independently installs selected DBLayer 6, CacheLayer 4 and Runwire 2.1.1 optional integrations, checks platform requirements, and runs the Runwire forwarding smoke.
- Sphinx `-n -W` strict HTML build passes with no suppressed warnings.

## Checkpoint

The final follow-up changes remove PHPForge-forbidden output in the FPM example and apply the exact Pint whitespace fix; the formatter configuration is unchanged. Full repo PHPForge validation passed in Security & Standards run `37730128164` on the following release-preflight branch head `7c953fa3b32a8fdb5feb84ab244214144ebb50e9`, which contains this unchanged Batch 7 example and documentation content. Batch 6 performance/regression acceptance remains explicitly deferred and unwaived.

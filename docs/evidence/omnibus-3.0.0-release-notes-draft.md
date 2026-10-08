# Omnibus 3.0.0 — Release notes (draft, unpublished)

**Compared against immediate previous published tag: `2.6`.** This is a release candidate document for review, not a published release or approval to tag.

## Highlights

- Omnibus remains a standalone, framework-agnostic PHP event/message bus with explicit runtime construction and no implicit framework or host-process ownership.
- Native Runwire 2.1.1+ passed-instance composition supports explicit runtime/request/coroutine context forwarding across entry points, with fiber-aware binding, cancellation checkpoints and host-owned request lifetimes. No Runwire dependency is installed unless the consumer selects it.
- UID 6, DBLayer 6, and CacheLayer 4 are the supported integration generations. Database and CacheLayer integrations remain optional; ordinary dispatch and consumers do not require PCNTL/POSIX at Composer install.
- Worker signal registration is opt-in via `WorkerOptions(handleSignals: true)`; native multi-process worker pools still require process extensions.
- Workflow dispatch/recovery, failure retry claims, detached uniqueness, worker cleanup and expired-lease handling are hardened against stale ownership, ambiguous delivery and unintended replay.
- Failure pruning now preserves active retry and sent-state reconciliation records.
- Executable standalone/Runwire forwarding, packed production consumer and actual FastCGI/FPM probes supplement documentation and PHPForge quality gates.

## Upgrade considerations

- Upgrade any installed optional DBLayer 5, CacheLayer 3 or Runwire 1 generations before constructing Omnibus 3 adapters. UID 6 has its own 64-bit PHP and `ext-ctype` platform requirements.
- Coordinate worker/producer/administrative process cutover for durable storage. Keep the 2.6 portable payload-wrapper policy intact; **2.5 readers must not** read 2.6+ written rows.
- Pass an active host-created Runwire runtime and request explicitly through `withRunwire`; complete requests at their host-owned lifecycle boundary and never forward a completed request. Avoid implicit parent-Fiber context propagation.
- Maintain idempotent handler semantics. Ambiguous sends are at-least-once, not exactly-once. Do not blindly resend workflows or in-flight failure retries.
- Measure handler p99, settlement, queue prefetch waiting and scheduling jitter before configuring visibility/claim/uniqueness leases.
- Use `docs/upgrading.rst`, `docs/operations.rst` and `docs/consumer-validation.rst` for deployment and recovery recipes.

## Verification and publication status

Batches 0–7 implementation and QA plus the Batch 8 nonperformance preflight are tracked in `docs/plans/omnibus-next-release-audit-plan.md`; the PHPForge release preflight and 300-second single-process host soak have passed; final matched performance comparison and release authorization still belong to Batch 8. The recurring consumer/docs checks now run in `security-standards.yml`, and the acceptance-validator self-test runs in `stable-performance.yml`; the batch-specific workflows have been removed.

**Release blocking:** Final-candidate seven-trial matched acceptance on the owner-selected GitHub-hosted runner and publication approval remain open. The active RPM policy retains 2% for unbound dispatch and permits an owner-approved 3% overhead cap only for Runwire binding relative to the same host context; p95/p99, CPU, RSS, variance and correctness limits remain enforced. This is an accepted integration cost, not a demonstrated speed improvement or dedicated-machine stability certification. Deep 100k MySQL/PostgreSQL and SQLite 1m retention evidence, actual multi-worker CPU/RSS and backend discovery counts are recorded. Hosted component timings do not certify application performance. **Do not merge, tag, or publish** until these gates are explicitly closed.

## Advisory audit note

Composer's full lockfile currently includes the abandoned transitive **development** dependency `doctrine/annotations`. The final-preflight workflow verifies core production advisories with `composer audit --locked --no-dev`, then audits all locked dependencies while **reporting** inherited abandonment (not hiding it) using `--abandoned=report`. Actual security advisories remain fatal in both passes. No upstream package/version changes are silently applied to bypass PHPForge.

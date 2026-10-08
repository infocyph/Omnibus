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

The implementation plan is complete and removed. Revision `76503abc5fb82957bafd0695bfe8749a5427cd6d` passed [consolidated QA](https://github.com/infocyph/Omnibus/actions/runs/37776798293) and [seven-trial matched performance acceptance](https://github.com/infocyph/Omnibus/actions/runs/37776789983) on 2026-10-08. Local PHPForge checks passed with 239 tests and 1,280 assertions. The unchanged source also passed the prior 300-second single-process host soak; that lifecycle smoke is distinct from the HTTP process-tree measurements. Historical deep-storage and failed performance attempts remain in this evidence directory.

Measured RPM regression was **0.327% unbound versus 2.6**, and binding overhead was **1.419% versus the same host context**. The active policy retains a 2% unbound cap and an owner-approved 3% bound cap; p95/p99, CPU, RSS, variance and correctness limits remain enforced. The passing run also meets the original 2% bound cap. Its scope is GitHub-hosted matched acceptance, not dedicated-machine stability or a production throughput guarantee.

Consumer, package/FPM and documentation checks run in `security-standards.yml`; the acceptance-validator self-test and representative comparison run in `stable-performance.yml`. These workflows must pass for any subsequent release candidate revision, including documentation cleanup. The draft PR remains unpublished; merge, tagging and publication require the owner's instruction.

## Advisory audit note

Composer's full lockfile currently includes the abandoned transitive **development** dependency `doctrine/annotations`. PHPForge audits all locked dependencies using `--abandoned=report`, reporting inherited abandonment while treating security advisories as fatal. The separate `composer audit --locked --no-dev` check verifies core production dependencies. No upstream package/version changes are silently applied to bypass PHPForge.

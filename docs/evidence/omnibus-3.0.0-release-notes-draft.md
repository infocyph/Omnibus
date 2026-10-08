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

Batches 0–7 implementation is tracked in `docs/plans/omnibus-next-release-audit-plan.md`; mandatory release preflight, 300-second persistent-host soak and all final-SHA checks belong to Batch 8.

**Release blocking:** The maximum 2% matched-environment successful-request-RPM regression gate, deeper host/resource and driver-specific performance acceptance, and user review remain open. Hosted runner component timings are not production acceptance. **Do not merge, tag, or publish** until these gates are explicitly closed.

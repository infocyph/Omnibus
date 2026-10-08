# Omnibus-owned Runwire binding overhead remediation

The review of `6857fba698bc7ff984bec0b106de9691f55dfa03` reproduced all three corrected integrity/lifetime boundaries, but its [seven-trial hosted acceptance](https://github.com/infocyph/Omnibus/actions/runs/37755456311) failed: Runwire-bound HTTP RPM was 3.642% below the host-only control against the unchanged 2% limit. Unbound 3.0 regressed 1.278% against 2.6. Per-variant RPM CV stayed below 1%.

## Attribution and ownership

The host-only and bound HTTP variants both construct and complete the same Runwire 2.1.1 runtime/request types. The additional bound work enters Omnibus's `RunwireBinding`. Inspection found three costs owned by that integration:

- the immutable runtime identity string was rebuilt at admission and every operation;
- connection and Fiber registries were allocated even for a synchronous operation using neither;
- `class_exists()` autoloaded the optional CacheLayer integration merely to discover that the host had not bound it. Unlike a warmed component profile, PHP request handling repeats this cold class-loading work.

No Runwire defect or dependency change is required for these reductions. This is not a universal claim about all Runwire workloads; the evidence concerns the measured Omnibus adapter path. Runwire's absence of a public scope-liveness query still requires a guarded `barrier(1)` call to validate a borrowed scope. That check is retained, and the failing HTTP benchmark supplies no coroutine scope.

## Delivered changes

`RunwireBinding` retains a computed identity key only inside the execution-local context record. It reads the live generation ledger and checks PID at every operation. Mutable request completion/cancellation and scope usability remain checked. Cleanup inherits the identity key but creates its own bounded request lifetime.

WeakMaps are allocated on first connection registration or Fiber binding. Connection keys remain weak, contexts remain Fiber-isolated, and completed bindings restore their previous owner.

CacheLayer sharing checks already-loaded classes. A host calling `RunwireIntegration::bind()` loads that class itself; late binding inside the outer callback is picked up at nested operation entry. Omnibus neither initializes nor releases the host's global CacheLayer binding. Adapter callbacks are composed without a redundant initial pass-through closure.

## Verification and limits

The added regressions cover an installed-but-unused CacheLayer integration in a fresh PHP process, late host CacheLayer binding, a host closing its scope during an active binding, and inherited bindings after an actual fork. Existing stale-generation, closed-scope, late DB registration, cancellation/cleanup, and concurrent request-restoration regressions remain mandatory.

Local PHP 8.5.4 `composer ic:process`, `composer ic:tests:details`, and `composer ic:release:guard` passed: **238 tests / 1,276 assertions**, zero advisory findings, unchanged static/complexity limits. SQL Server remains unavailable on this host; live service coverage belongs to the configured hosted matrix.

Supporting 100,000-operation, three-trial component attribution measured added bound-dispatch cost at about 1.67 microseconds before remediation and 1.21 microseconds after identity reuse/lazy registries. These are non-isolated component measurements, not HTTP RPM acceptance. The cold-load fix is separately covered by the fresh-process regression.

Three alternating local four-worker HTTP pairs completed 40,000 correct warm-c4 responses per trial with no failed responses. Host-only RPM CV was 13.17% and bound CV was 9.24%, so this environment cannot establish the 2% release criterion. No local throughput claim is accepted from those noisy measurements.

Final acceptance remains the exact committed candidate's hosted seven-trial HTTP comparison, full PHP/service/dependency matrix, consumers/docs, and release preflight. All RPM, tail, CPU, RSS, correctness, and variance limits remain unchanged. The workflow artifacts carry the candidate SHA and authoritative post-commit results; a failing or inconclusive result does not become a pass through this document.

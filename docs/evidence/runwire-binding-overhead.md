# Omnibus-owned Runwire binding overhead remediation

The review of `6857fba698bc7ff984bec0b106de9691f55dfa03` reproduced all three corrected integrity/lifetime boundaries, but its [seven-trial hosted acceptance](https://github.com/infocyph/Omnibus/actions/runs/37755456311) failed: Runwire-bound HTTP RPM was 3.642% below the host-only control against the unchanged 2% limit. Unbound 3.0 regressed 1.278% against 2.6. Per-variant RPM CV stayed below 1%.

The first remediation, `045038a28dd3740f003b2df2356f7aa2356356e3`, passed its full QA, consumers and preflight, but [hosted acceptance](https://github.com/infocyph/Omnibus/actions/runs/37763453626) still failed: binding RPM regression decreased to **2.451%**, above the same 2% limit. Unbound 3.0 was 0.636% faster than 2.6; all other budgets passed and per-variant RPM CV remained below 1%. That failed result is retained, not waived or dismissed as noise.

The first-use follow-up, `e326a3b9932f7f3c099722ecb3ca6b462d29740b`, also passed [full QA](https://github.com/infocyph/Omnibus/actions/runs/37768088690), [consumers/docs](https://github.com/infocyph/Omnibus/actions/runs/37768082955), and [release preflight](https://github.com/infocyph/Omnibus/actions/runs/37768082941). Its [seven-trial hosted performance acceptance](https://github.com/infocyph/Omnibus/actions/runs/37768082950) **failed**: bound RPM regression is **2.349%**, unbound regression is **1.391%**, and the sole failing budget is the bound 2% RPM limit. Host-only and bound RPM CV are 0.355% and 0.360%, respectively. Bound tail latency, CPU and RSS budgets pass. The improvements have not closed performance certification; no Runwire defect has been demonstrated.

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

The follow-up combines PID/generation and request/scope validation in one method, computes identity directly without an intermediate array, and captures the current Fiber once at admission. Root execution assigns/restores its context directly; Fiber execution retains the WeakMap path. Compatibility checks run only for nested bindings. A never-populated connection registry uses a null check; an emptied WeakMap follows the general adapter path safely. PID lookup failure rejects ownership rather than treating it as PID zero. Every operation still reads current PID, generation, request completion/cancellation and scope usability.

## Verification and limits

The added regressions cover an installed-but-unused CacheLayer integration in a fresh PHP process, late host CacheLayer binding, a host closing its scope during an active binding, inherited bindings after an actual fork, and collected host connections leaving the binding usable. Existing stale-generation, closed-scope, late DB registration, cancellation/cleanup, and concurrent request-restoration regressions remain mandatory.

Local PHP 8.5.4 `composer ic:process`, `composer ic:tests:details`, and `composer ic:release:guard` passed: **239 tests / 1,280 assertions**, zero advisory findings, unchanged static/complexity limits. SQL Server remains unavailable on this host; live service coverage belongs to the configured hosted matrix.

Supporting 100,000-operation, three-trial component attribution measured added bound-dispatch cost at about 1.67 microseconds before remediation and 1.21 microseconds after identity reuse/lazy registries. These are non-isolated component measurements, not HTTP RPM acceptance. The cold-load fix is separately covered by the fresh-process regression.

Further profiling used the existing PHP 8.4 container and a separate 100-sample fresh-process comparison. The latter measured a first bound operation at about 31.48 microseconds before the follow-up and 28.77 microseconds after it, with unbound controls near 15.7 microseconds. This isolates supporting first-use cost; it does not certify host HTTP throughput. A direct HTTP-SAPI probe also confirmed OPcache is active in `cli-server` despite the parent CLI reporting `enable_cli=0`; that parent setting is not evidence of disabled HTTP OPcache. No benchmark configuration or budget was changed.

Three alternating local four-worker HTTP pairs completed 40,000 correct warm-c4 responses per trial with no failed responses. Host-only RPM CV was 13.17% and bound CV was 9.24%, so this environment cannot establish the 2% release criterion. No local throughput claim is accepted from those noisy measurements.

The follow-up's fresh 300-second process-local consumer soak completed 18,096,152 jobs, left queue depth zero, and reported zero PHP allocated-memory growth with an 8 MiB peak. This checks the CLI consumer lifecycle; it does not establish cross-process RSS stability or HTTP throughput.

## Engineering-principles optimization review

The review applies [PHPForge's optimization and acceptance rules](../../vendor/infocyph/phpforge/resources/engineering-principles.md), including allocations, bounded reuse, call overhead, runtime capability resolution, Composer loading, and SAPI-specific OPcache verification.

| Approach | Disposition and constraint |
| --- | --- |
| Reduce allocation and cold loading | Applied lazy WeakMaps and avoided autoloading an unused optional CacheLayer integration. Host bindings remain discoverable even when established inside an active callback. |
| Reuse immutable computed values | Applied execution-local runtime identity reuse. PID, generation, completion, cancellation and scope liveness remain live checks. |
| Simplify control flow and reduce call overhead | Applied direct root-context assignment/restoration, first-use validation simplification, and removal of a redundant callback wrapper. No additional production classes or raised complexity limits. |
| Change the context array representation | Packed-tuple prototypes were measured outside the repository and discarded because they did not establish a repeatable improvement. |
| Combine Runwire cancellation internals | An isolated prototype did not establish a repeatable benefit. Neither installed vendor code nor the Runwire repository was changed. This experiment does not establish an upstream defect. |
| Optimize Composer and OPcache setup | Optimized authoritative autoloading is already configured. Actual HTTP-SAPI OPcache was checked rather than inferred from parent CLI flags. No speculative JIT, preloading or optimizer-flag change was accepted. |
| Remove repeated validation | Immutable identity is reused, but host-owned mutable lifetimes can change inside callbacks. Checks at admission and operation entry remain necessary; the fork, stale-generation, cancellation and closed-scope regressions must stay green. |

Component and fresh-process profiles guide implementation choices; only the representative hosted comparison decides HTTP RPM acceptance. Further changes require measured benefit with these same ownership/lifecycle boundaries and unchanged budgets.

## Full HTTP profile and approved integration cost (2026-10-08)

The follow-up source `e326a3b9932f7f3c099722ecb3ca6b462d29740b` was profiled with [XHProf 2.3.10](https://pecl.php.net/package/xhprof/2.3.10) compiled inside the existing `php:8.4-cli-bookworm` container. The workspace was mounted read-only. A temporary router enabled function profiling around the original `benchmarks/release-host.php`; it changed only variant selection and profile capture. Four HTTP workers processed eight alternating blocks of 50 requests per variant, after 25 unprofiled warm-up requests for each variant. All **800 profiled responses** were HTTP 200 with exactly `{"ok":true,"value":42}`.

The captured call graph has 798 median calls for host-only and 826 for bound, an additional 28 calls. The outer Composer `ClassLoader::loadClass()` count is 38 for each variant; no additional cold-loading gap was found. The added binding work includes:

| Boundary | Calls per bound request |
| --- | ---: |
| `RunwireBinding::withRunwire()` | 1 |
| `RunwireBinding::assertContext()` | 2 |
| `RequestContext::runtime()` / `completed()` | 2 each |
| `CancellationToken::throwIfCancelled()` / `refreshDeadline()` | 2 each |

Admission validation protects the arbitrary host callback. Operation validation protects dispatch after that callback may have changed completion, cancellation, scope, PID or generation state. Removing the second checkpoint would reintroduce covered semantic failures. Profiling did not identify a further obvious safe reduction with a demonstrated benefit. Instrumented timings are not uninstrumented throughput evidence; XHProf is absent from release acceptance. Raw session profiles and the aggregation are retained under `/tmp/omnibus-http-profile/`, not distributed with the library.

Following the owner's approval to proceed with profiling and the scoped cost trade-off, the active acceptance policy is `omnibus-3.0-scoped-runwire-2026-10-08`:

- Unbound-vs-2.6 successful-RPM regression remains capped at **2%**.
- Bound-vs-host-only successful-RPM overhead is capped at **3%**. The observed 2.349% is accepted as an integration cost; the cap provides 0.651 percentage points of headroom. This is an engineering capacity decision, not a claim that the failed 2% result was statistical noise.
- p95 (15%), p99 (20%), CPU (5%), RPM CV (2%), RSS growth (4 MiB per 10k requests), correctness, minimum measurement windows and worker accounting retain their existing limits.
- Revisit the bound cost budget when the binding/runtime implementation or representative workload changes. Future regressions over 3% still fail; request lifetime and ownership checks cannot be removed for speed.

The validator exports each comparison's active limits and the policy identifier. Its adversarial self-test accepts 2.5% only for the bound comparison, rejects 2.5% unbound regression, and rejects 3.1% bound overhead, alongside existing malformed/correctness/runner-mode checks. Re-evaluating the old `e326a3b` measurements passes this scoped policy, but is explicitly **historical reassessment, not fresh final-candidate certification**. Its original Actions run remains failed under the original 2% contract.

The batch-specific workflows are removed. Consumer/examples, independent production packaging/FPM and strict documentation checks are consolidated into `security-standards.yml`; PHPForge retains the supported-runtime/service/dependency matrix and quality/advisory checks. The acceptance self-test runs in `stable-performance.yml` before the seven-trial comparison. The redundant release QA workflow no longer repeats the same checks.

Final acceptance requires the updated candidate's hosted seven-trial comparison and full consolidated QA. The workflow artifacts carry the candidate SHA, lock hashes, active limits and authoritative post-commit results. No merge, tag or publication is authorized by accepting this integration cost.

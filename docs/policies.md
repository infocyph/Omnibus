# Coordination and execution policies

Every policy is an opt-in decorator. Applications that do not construct one pay
no lookup, cache, lock, clock, or telemetry cost.

`UniqueSender` acquires a detached CacheLayer lease before enqueue and adds a
`UniqueStamp`. `UniqueTransport` refreshes that lease when a retry releases the
message and ends it only after acknowledgement or terminal rejection. Because
the handle crosses a process boundary, queued uniqueness requires a token-based
provider that satisfies `DetachedLeaseProvider`. Wrap only a CacheLayer provider
that can release a reconstructed key/token handle. A process-bound file lock is
suitable for overlap protection, not detached queued uniqueness.

`OverlapProtectionScope` holds the original CacheLayer handle while a handler
runs. It refreshes before returning; refresh failure throws `LeaseLost`, and the
handle is always released. Set the lease longer than ordinary execution and
use Console's hard timeout as the outer bound.

`FixedWindowRateLimitScope` increments a CacheLayer atomic counter keyed by the
configured identity and time bucket. `CircuitBreakerScope` serializes state
changes with a CacheLayer lease and stores failure/open state in atomic
counters. An open circuit becomes eligible for a probe after the recovery
window.

These exceptions enter the normal retry strategy. `WorkflowCancelled` is marked
non-retryable. Applications may supply another `RetryStrategy` when overlap,
rate, or circuit exceptions need distinct delays.

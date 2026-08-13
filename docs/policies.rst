Coordination and execution policies
===================================

Policies are opt-in decorators. Applications that do not construct one perform
no policy cache, lock, counter, or clock operation.

Policy keys contain 1 through 512 bytes and no ASCII control characters.

Unique messages
---------------

``UniqueSender`` acquires a detached CacheLayer lease before enqueue and adds a
``UniqueStamp``. If send fails, it releases the lease.

``UniqueTransport``:

* keeps the lease across delivery;
* refreshes it before release for the original lease plus retry delay;
* releases it, best effort, only after successful acknowledgement or terminal
  rejection;
* raises ``LeaseLost`` if retry safety cannot be maintained.

Queued uniqueness is bounded duplicate suppression while the lease is valid;
it is not permanent uniqueness or exactly-once delivery. It requires a
token-based provider implementing ``DetachedLeaseProvider``. CacheLayer 3.1
Redis/Valkey and Memcached providers can be adapted; process/session-bound file
and advisory locks are rejected. If cleanup fails after durable settlement,
the queue result remains successful and the lease expires by TTL.

Overlap protection
------------------

``OverlapProtectionScope`` holds the original CacheLayer lock while the handler
runs, verifies ownership before returning, and releases in ``finally``. Configure
``normal handler duration < hard worker timeout < lock lease``. A post-handler
check cannot make execution safe after a lease expires; Omnibus does not run a
background heartbeat. Lease loss detected after the handler returns is a
dedicated non-retryable post-execution failure, preventing automatic replay of
business work that may already be durable.

Rate limits
-----------

``FixedWindowRateLimitScope`` increments an atomic counter keyed by policy
identity and time bucket. Its maximum and window must be positive integers. The
counter operation is atomic in the selected CacheLayer backend.

Circuit breaker
---------------

``CircuitBreakerScope`` serializes breaker-state mutations with a short lock and
stores failure/open state in atomic counters. Once the failure threshold is
reached, calls fail with ``CircuitOpen``. After the recovery window, exactly one
caller owns the half-open probe while an open marker keeps concurrent callers
out. Probe success closes the breaker; probe failure re-arms the recovery
window. The open marker outlives both the recovery boundary and probe lease, so
an expiring counter cannot accidentally look closed at half-open. Configure the
probe lease longer than the maximum supported handler lifetime. Probe ownership
expires after a crashed worker. Success cleanup and failure bookkeeping are
best effort after handler execution and never replace the handler outcome.

Policy exceptions enter the configured retry strategy. Applications can supply
a strategy that assigns different attempt limits or delays to overlap,
rate-limit, circuit, and lease failures.

CacheLayer backend choices
--------------------------

The coordination decorators depend on CacheLayer contracts rather than Redis.
CacheLayer's Memcached lock provider can be wrapped with
``DetachedLeaseAdapter`` and used for uniqueness and overlap protection. Its
token ownership and CAS refresh/release behavior preserve lease ownership.

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
   use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;

   $memcached = new Memcached();
   $memcached->addServer('127.0.0.1', 11211);

   $locks = new DetachedLeaseAdapter(
       new MemcachedLockProvider($memcached, 'my-application:locks:'),
   );

Memcached coordination remains ephemeral: eviction or a Memcached restart can
drop lease state. That may be acceptable for overlap protection or best-effort
duplicate suppression, but it must not be confused with durable queue storage.
Memcached release installs a one-second CAS tombstone to prevent a release from
deleting a newer owner's lease. Configure uniqueness acquisition with at least
a two-second wait when immediate post-settlement re-enqueue is required.
Combine Memcached-backed policies with ``DBLayerTransport`` when Redis is
unavailable and queued messages must survive restarts.

Rate limiting and circuit breaking require CacheLayer's
``AtomicCounterStoreInterface``. CacheLayer 3.1 ships Redis and Valkey counter
stores. An application may supply another implementation with equivalent
atomic increment, read, delete, and TTL semantics; a normal PSR cache adapter is
not sufficient.

Logical policy identifiers are validated and hashed into bounded backend-safe
storage keys. This permits domain identifiers such as ``tenant:42`` without
violating Redis counter or Memcached key restrictions and avoids exposing the
logical value in backend key listings.

See :ref:`memcached-uniqueness-with-a-durable-database-queue` for the complete
producer and consumer decorator order.

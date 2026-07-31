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
* releases it only after successful acknowledgement or terminal rejection;
* raises ``LeaseLost`` if retry safety cannot be maintained.

Queued uniqueness requires a token-based provider implementing
``DetachedLeaseProvider``. A process-bound file lock cannot safely cross the
producer/consumer boundary.

Overlap protection
------------------

``OverlapProtectionScope`` holds the original CacheLayer lock while the handler
runs, verifies refresh before returning, and releases in ``finally``. Configure
the lease longer than normal execution and enforce a larger hard timeout in the
host process.

Rate limits
-----------

``FixedWindowRateLimitScope`` increments an atomic counter keyed by policy
identity and time bucket. Its maximum and window must be positive integers. The
counter operation is atomic in the selected CacheLayer backend.

Circuit breaker
---------------

``CircuitBreakerScope`` serializes breaker-state mutations with a short lock and
stores failure/open state in atomic counters. Once the failure threshold is
reached, calls fail with ``CircuitOpen`` until the recovery window permits a
probe. A successful probe clears failure and open state.

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
Combine Memcached-backed policies with ``DBLayerTransport`` when Redis is
unavailable and queued messages must survive restarts.

Rate limiting and circuit breaking require CacheLayer's
``AtomicCounterStoreInterface``. CacheLayer 2.0 ships Redis and Valkey counter
stores. An application may supply another implementation with equivalent
atomic increment, read, delete, and TTL semantics; a normal PSR cache adapter is
not sufficient.

Logical policy identifiers are validated and hashed into bounded backend-safe
storage keys. This permits domain identifiers such as ``tenant:42`` without
violating Redis counter or Memcached key restrictions and avoids exposing the
logical value in backend key listings.

See :ref:`memcached-uniqueness-with-a-durable-database-queue` for the complete
producer and consumer decorator order.

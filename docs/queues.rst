Queues, retries, and failures
=============================

Delivery lifecycle
------------------

``Sender``, ``Receiver``, and ``Transport`` define the queue boundary.
Receivers reserve messages for a bounded visibility period and return
``Reservation`` objects.

``Consumer`` applies this order:

1. reserve a bounded batch;
2. persist a poison or terminal failure;
3. reject terminal work only after persistence succeeds;
4. release retryable work with its retry delay;
5. acknowledge only after the handler and execution scope succeed.

Settlement failures are infrastructure failures. They propagate and are never
reclassified as handler failures. If failure persistence is unavailable, the
reservation remains active and can be reclaimed after visibility expires.

Retry policy
------------

``ExponentialRetryStrategy`` defaults and validation:

.. list-table::
   :header-rows: 1

   * - Option
     - Default
     - Valid values
   * - ``maximumAttempts``
     - ``3``
     - Integer greater than or equal to 1.
   * - ``initialDelaySeconds``
     - ``1.0``
     - Finite float greater than or equal to 0.
   * - ``multiplier``
     - ``2.0``
     - Finite float greater than or equal to 1.
   * - ``maximumDelaySeconds``
     - ``60.0``
     - Finite float greater than or equal to 0.
   * - ``jitterRatio``
     - ``0.0``
     - Float from 0.0 through 1.0.

Failures implementing ``NonRetryableFailure`` bypass remaining retry capacity.
``HandlerNotFound`` and ``WorkflowCancelled`` are non-retryable.

Poison payloads
---------------

Malformed JSON, oversized payloads, unknown aliases, and codec failures become
poison reservations. The consumer stores the bounded raw payload and decode
error, then rejects it. Poison work is not returned to the ready queue.

Failure management
------------------

``FailureManager`` retries decoded failures only. It removes a record after the
selected sender accepts the message. If send fails, the failure record remains.
A raw failure stays inspectable until its codec is restored; it cannot be
retried as an object.

``forget()``, ``flush()``, and ``prune()`` delegate to the selected failure
store. Failure-list limits range from 1 through 1000.

Delivery guarantee
------------------

All durable transports are at-least-once. A crash after a handler side effect
but before acknowledgement can redeliver the message. Protect durable effects
with domain idempotency keys, database uniqueness, transactions, or another
explicit deduplication design.

``size()`` reports currently visible depth. Delayed and actively reserved work
is excluded. A broker adapter may declare this estimate inexact.

The in-memory transport is deterministic process-local infrastructure. It is
not a durable or cross-worker fallback.

See :ref:`complete-sqlite-durable-queue`, :ref:`host-owned-worker-loop`, and
:ref:`failure-inspection-and-replay` for complete operational examples.

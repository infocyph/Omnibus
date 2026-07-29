# Queues, retry, and failure semantics

`Sender`, `Receiver`, and `Transport` define the queue boundary. A receiver
reserves messages for a bounded visibility period and returns `Reservation`
objects.

On success, `Consumer` acknowledges the reservation. On a retryable exception,
it releases the message with the configured delay. On a terminal failure, it
rejects the reservation and records a `FailedMessage`.

`ExponentialRetryStrategy` defaults:

| Option | Type | Default | Valid values |
| --- | --- | ---: | --- |
| `maximumAttempts` | integer | `3` | `>= 1` |
| `initialDelaySeconds` | float | `1.0` | finite, `>= 0` |
| `multiplier` | float | `2.0` | finite, `>= 1` |
| `maximumDelaySeconds` | float | `60.0` | finite, `>= 0` |
| `jitterRatio` | float | `0.0` | `0.0` through `1.0` |

Delivery is at-least-once: crashes and expired visibility reservations may
redeliver a message. Handlers must be idempotent or protect durable side effects
with application idempotency keys, transactions, or explicit deduplication.

In-memory transport is deterministic process-local infrastructure for tests and
single-process tools. It is not a durable or cross-worker fallback. Omnibus will
never silently replace a configured shared transport with it.

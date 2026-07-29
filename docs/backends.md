# Durable backends and recovery

No durable adapter initializes unless the application constructs it. All
backends provide at-least-once delivery; none claims exactly-once handler side
effects.

| Backend | Reservation | Batch | Delay | Depth |
| --- | --- | --- | --- | --- |
| DBLayer | transaction plus conditional receipt | up to `1000` | native timestamp | exact ready depth |
| Redis/Valkey | atomic Lua scripts | up to `1000` | sorted-set timestamp | exact ready/expired depth |
| AMQP | provider backend capability | declared by backend | topology/plugin dependent | provider-declared |
| SQS | receipt handle and visibility | provider maximum | native, provider-limited | approximate |

## DBLayer

Run every statement returned by `QueueSchema::statements($driver)` in an
application migration. Supported drivers are `mysql`, `pgsql`, and `sqlite`.
The statements create queue, failure, workflow, and workflow-item tables plus
their ready/failure/pending indexes.

`DBLayerTransport` selects eligible rows inside a transaction. MySQL and
PostgreSQL use `FOR UPDATE SKIP LOCKED`; SQLite relies on its serialized writer
transaction. A batch is marked with one reservation token, while every public
receipt contains both the row ID and token. Ack/release/reject updates are
conditional, so an expired stale worker cannot settle a newer reservation.

If a worker crashes, wait for `reserved_until` to expire. A later receive
reclaims the row and increments its attempt. Do not manually clear receipts
while workers are active.

## Redis and Valkey

`RedisTransport` uses one Redis hash tag per queue so all keys stay in one
cluster slot. Send, receive/reclaim, ack/reject, release, and size use atomic Lua
scripts. `CallbackRedisClient` can adapt phpredis without a hard extension
dependency:

```php
$client = new CallbackRedisClient(
    static fn(string $command, string ...$arguments): mixed =>
        $redis->rawCommand($command, ...$arguments),
);
```

Expired reservations are moved back to the ready set during receive. A stale
token cannot settle a reclaimed message. Recovery requires only healthy Redis
connectivity; do not delete individual hashes/zsets because they form one queue
record set.

## AMQP and SQS

`AmqpBackend` and `SqsBackend` are native provider boundaries. An SDK adapter
must translate its delivery tag/receipt handle, visibility, reject/dead-letter,
delay, prefetch/batch, and depth behavior. `BrokerCapabilities` declares delayed
delivery, delayed release, exact versus approximate depth, and maximum receive
batch. Omnibus rejects unsupported requests instead of silently emulating them.

AMQP delayed delivery commonly requires broker topology or a delayed-message
plugin. SQS depth is approximate and FIFO deduplication remains an SQS adapter
concern. Provider credentials and clients are never loaded by other transports.

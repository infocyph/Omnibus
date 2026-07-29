# Integration

## Console

Console will optionally consume Omnibus after its first stable release.
Scheduled entries retain Console's compiled cron and mutex semantics and invoke
`ScheduledMessageDispatcher` with an explicit factory key. Only that key enters
the schedule manifest; arbitrary closures and constructed messages are not
serialized into it.

Console also owns message-consumer command rendering and subprocess
supervision. Omnibus owns the work performed by one consumer.

## DBLayer

`Integration\DBLayer\AfterCommitDispatcher` registers dispatch through
`Connection::afterCommit()`. Dispatch occurs immediately outside a transaction,
after the outermost successful commit inside a transaction, and not after a
rollback.

`DBLayerTransport`, `DBLayerFailureStore`, and `DBLayerWorkflowStore` use the
application's existing connection. `QueueSchema::statements()` supplies
driver-specific DDL for application migrations; adapters never create or alter
tables during dispatch or consumption.

## CacheLayer

Unique-message, overlap, fixed-window rate-limit, and circuit-breaker decorators
adapt CacheLayer's lock and atomic-counter contracts. They are constructed only
when selected. Omnibus does not introduce another cache/lock provider
hierarchy.

## Foundation and InterMix

Foundation installs and wires Omnibus only when the application enables the
module. It supplies handler/listener factories, configuration, authorization,
after-response integration, and an `ExecutionScope` backed by InterMix so every
message receives a fresh job scope and cleanup on success or failure.

Omnibus supplies only the `AfterResponseRuntime` contract. Foundation adapts
the active HTTP runtime and must not construct it for CLI consumers.

## Broadcasting

`Broadcaster` is a provider boundary. `ChannelAuthorizer` accepts an application
principal supplied by Foundation; Omnibus does not resolve authentication.
Broadcast providers remain optional and must not initialize for non-broadcast
messages.

`CallbackBroadcaster` is the SDK-neutral provider adapter. Provider
authentication, connection management, and channel authorization stay outside
Omnibus.

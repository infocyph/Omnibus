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

Future DB queue and failure adapters will use DBLayer connections and schema
definitions rather than adding another connection or migration layer.

## CacheLayer

Future unique-message, overlap, rate-limit, circuit-breaker, and distributed
workflow coordination will adapt CacheLayer's existing backends and lease
contracts. Omnibus will not introduce another cache/lock provider hierarchy.

## Foundation and InterMix

Foundation installs and wires Omnibus only when the application enables the
module. It supplies handler/listener factories, configuration, authorization,
after-response integration, and an `ExecutionScope` backed by InterMix so every
message receives a fresh job scope and cleanup on success or failure.

## Broadcasting

`Broadcaster` is a provider boundary. `ChannelAuthorizer` accepts an application
principal supplied by Foundation; Omnibus does not resolve authentication.
Broadcast providers remain optional and must not initialize for non-broadcast
messages.

# Events and queued listeners

`EventDispatcher` implements PSR-14. Listener execution is synchronous, ordered
as configured, and exceptions propagate according to PSR-14. Stoppable events
are checked before every listener.

`ListenerMap` receives explicit class/interface-to-listener mappings. It does
not scan directories, attributes, or docblocks.

A listener object implementing `ShouldQueue` is not invoked synchronously.
Instead, the dispatcher sends a `QueuedListener` message through the configured
`MessageBus`. The listener and its event must have explicit safe serialization
aliases before a durable transport can accept them.

If a queued listener is configured without a message bus, dispatch fails
explicitly. Synchronous-only applications can construct the dispatcher without
a bus and do not load queue transports.

Use a direct method call when the caller requires the collaborator's result or
when the operation is mandatory validation, authorization, persistence, or
transaction flow.

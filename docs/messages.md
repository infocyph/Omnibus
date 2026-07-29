# Messages, routing, and envelopes

Messages are ordinary application objects. `Envelope` adds message-lifecycle
metadata as typed `Stamp` objects without requiring the message to inherit from
an Omnibus base class.

Core stamps currently include:

| Stamp | Meaning |
| --- | --- |
| `MessageIdStamp` | Stable ULID assigned once before dispatch |
| `RouteStamp` | Selected transport and queue |
| `DelayStamp` | Earliest delivery delay in seconds |
| `AttemptStamp` | Current reservation attempt |
| `HandledStamp` | Synchronous handler result; never serialized |

`RouteMap` and `HandlerMap` accept explicit class/interface maps. Exact message
classes win; parent/interface mappings provide polymorphic defaults. Resolved
lookups are cached per map instance.

The default route is `sync/default`. Configure another default explicitly if
the application wants asynchronous-by-default behavior. Omnibus never silently
falls back to another transport when a named transport is missing.

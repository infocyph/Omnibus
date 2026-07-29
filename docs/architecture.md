# Architecture and ownership

Omnibus owns the lifecycle of application messages:

- routing messages to synchronous handlers or named transports;
- synchronous application-event listener dispatch;
- asynchronous reservations, acknowledgement, release, retry, and terminal
  failure;
- message serialization, payload versions, and safe type aliases;
- chains, batches, queued listeners, and broadcasts as message workflows;
- consumer execution and per-message reset boundaries;
- queue telemetry and test fakes.

It does not duplicate the following owners:

| Owner | Retained responsibility |
| --- | --- |
| Console | Commands, compiled schedules, subprocess supervision, signals, scaling, and process limits |
| DBLayer | Connections, transactions, schema/migrations, and repository persistence |
| CacheLayer | Cache backends, leases, distributed locks, and shared policy state |
| InterMix | Dependency resolution and application/job scopes |
| TalkingBytes | HTTP, email, webhook, and other communication protocols |
| Foundation | Application composition, authorization, module installation, configuration, and HTTP after-response lifecycle |

Omnibus never requires Console. After Omnibus is released, Console may expose
optional Omnibus commands and schedule adapters through `require-dev` and
`suggest`. Applications that use Console without Omnibus retain their existing
dependency and bootstrap surface.

Repository/application services emit state-change events explicitly after a
successful operation. Omnibus will not add Active Record hooks, model observers,
or hidden database queries.

Stable route, handler, listener, serializer, and message-factory maps are
provided explicitly or compiled during deployment. Dispatch performs no
filesystem scanning or reflection discovery.

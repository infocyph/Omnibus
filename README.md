# Infocyph Omnibus

Omnibus is a framework-agnostic, unified application-event bus and reliable
message queue for PHP.

It provides one message lifecycle for direct commands, synchronous events,
queued listeners, delayed work, scheduled dispatch, and optional broadcasts.
Applications can change a message from synchronous to asynchronous execution by
changing its explicit route rather than changing business code.

## Install

```bash
composer require infocyph/omnibus
```

The core requires only UID and the PSR clock/event contracts. DBLayer,
CacheLayer, Console, and external brokers remain optional and are loaded only
by their selected adapters.

## Current implementation

- Immutable envelope with typed extensible stamps.
- Explicit polymorphic message and listener maps with resolved-route caching.
- Direct synchronous handlers.
- PSR-14 synchronous events with ordered listeners and stoppable events.
- Opt-in queued listeners through the same message bus.
- Immediate and delayed in-memory transport.
- Durable DBLayer transport with transactional batch reservation.
- Redis/Valkey transport with atomic Lua reservation and settlement.
- Capability-aware AMQP and SQS provider boundaries.
- Bounded reservation visibility, acknowledgement, release, and rejection.
- Exponential retry with optional jitter.
- In-memory and DBLayer failure storage with retry, forget, flush, and prune.
- Versioned JSON envelopes with explicit safe type aliases and size/depth
  limits.
- Bounded custom binary/MessagePack serializer callback boundary.
- Bounded batch receive.
- Per-message execution-scope boundary for persistent-worker cleanup.
- DBLayer dispatch-after-commit adapter.
- CacheLayer uniqueness, overlap, rate-limit, and circuit-breaker decorators.
- Persistent DBLayer chains and batches with cancellation and named lifecycle
  events.
- Cooperative execution deadlines and host-owned after-response dispatch.
- Opt-in queue, wait, attempt, processing, retry, depth, and failure telemetry.
- Explicit scheduled-message factory keys for Console integration.
- Provider-neutral broadcast and channel-authorization contracts.
- Recording sender for tests.

Console commands and Foundation's InterMix/HTTP/auth composition intentionally
remain in their owning packages. See [the architecture and status
guide](docs/README.md).

## Minimal synchronous bus

```php
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

$handlers = new HandlerMap([
    CreateInvoice::class => static fn(CreateInvoice $message): string =>
        $invoiceService->create($message),
]);

$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport($handlers)]),
);

$result = $bus->dispatch(new CreateInvoice($accountId));
$invoiceId = $result->last(HandledStamp::class)?->result;
```

## Routing asynchronously

```php
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;

$routes = new RouteMap([
    CreateInvoice::class => new Route(
        transport: 'redis',
        queue: 'billing',
        delaySeconds: 2,
    ),
]);
```

The selected transport is the only transport resolved by dispatch. Consumers,
failure stores, broker clients, and unrelated listeners are not initialized by
synchronous routes.

## Quality checks

```bash
composer test
composer ic:ci
composer benchmark
composer soak:consumer
```

Backend schema, setup, delivery guarantees, and recovery procedures are covered
in [the backend guide](docs/backends.md). Workflow and coordination semantics
are covered in [the workflow guide](docs/workflows.md) and [the policy
guide](docs/policies.md).

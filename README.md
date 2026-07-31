# Omnibus

[![Security & Standards](https://github.com/infocyph/Omnibus/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Omnibus/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/omnibus?color=green)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/omnibus)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/omnibus/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Omnibus)
[![Documentation](https://img.shields.io/badge/Documentation-Omnibus-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/Omnibus)

A framework-agnostic event bus and reliable message queue for PHP.

Omnibus provides one explicit lifecycle for synchronous commands, PSR-14
events, queued listeners, delayed work, durable consumers, workflows,
scheduling adapters, and broadcasts. It works as a standalone Composer library
and does not require a framework or command package.

## Install

```bash
composer require infocyph/omnibus
```

Requirements:

- PHP `^8.4`
- `infocyph/uid`
- `psr/clock`
- `psr/event-dispatcher`

DBLayer, CacheLayer, Redis/Valkey clients, and broker SDKs are optional and load
only when their adapters are constructed.

## Highlights

- Explicit, cached route, handler, listener, codec, transport, and factory maps
- Direct synchronous handlers and ordered PSR-14 events
- In-memory, DBLayer, Redis/Valkey, AMQP, and SQS transport boundaries
- Conditional reservation settlement and visibility-based crash recovery
- Bounded retries, poison-payload capture, and durable failure management
- Safe versioned JSON envelopes with allow-listed aliases and strict limits
- CacheLayer uniqueness, overlap, rate-limit, and circuit-breaker decorators
- Redis-free operation through DBLayer, including zero-service SQLite; Memcached
  may back lease-based uniqueness and overlap but is not a queue transport
- Persistent chains and batches with idempotent terminal transitions
- Provider-neutral scheduling, broadcasting, after-response, and telemetry
- No filesystem scanning, hidden provider initialization, or runtime discovery

## Quick start

```php
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

$handlers = new HandlerMap([
    CreateInvoice::class => static fn (CreateInvoice $message): string =>
        $invoiceService->create($message),
]);

$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry([
        'sync' => new SyncTransport($handlers),
    ]),
);

$result = $bus->dispatch(new CreateInvoice($accountId));
$invoiceId = $result->last(HandledStamp::class)?->result;
```

Route selected messages asynchronously without changing the message or business
handler:

```php
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;

$routes = new RouteMap([
    CreateInvoice::class => new Route(
        transport: 'redis',
        queue: 'billing',
        delaySeconds: 2.0,
    ),
]);
```

`Consumer::run()` performs one bounded receive call. Any application loop,
scheduler, process manager, or CLI can invoke it directly.

## Delivery semantics

Durable transports provide at-least-once delivery. Terminal failures are
persisted before rejection, stale receipts cannot settle reclaimed work, and
telemetry failures cannot change queue or handler outcomes. Handlers that
produce durable side effects must remain idempotent.

## Quality checks

```bash
composer ic:tests
composer ic:ci
composer benchmark
composer soak:consumer
composer soak:durable
```

## Documentation

Read the complete [Omnibus documentation](https://docs.infocyph.com/projects/Omnibus),
including [getting started](https://docs.infocyph.com/projects/Omnibus/en/latest/getting-started.html),
[queue semantics](https://docs.infocyph.com/projects/Omnibus/en/latest/queues.html),
[durable backends](https://docs.infocyph.com/projects/Omnibus/en/latest/backends.html),
[serialization security](https://docs.infocyph.com/projects/Omnibus/en/latest/serialization.html),
[workflows](https://docs.infocyph.com/projects/Omnibus/en/latest/workflows.html), and
[operations](https://docs.infocyph.com/projects/Omnibus/en/latest/operations.html).

## Security

Protected by [PHPForge](https://github.com/infocyph/PHPForge), the automated
quality and security gate used across Infocyph PHP libraries.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Omnibus">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a> •
  <a href="https://github.com/infocyph/Omnibus/issues">Report Bug</a> •
  <a href="https://github.com/infocyph/Omnibus/issues">Request Feature</a>
</div>

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
- Bounded consumers, long-running workers, and optional fixed process concurrency
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

`Consumer::run()` performs one bounded receive call. `Worker` provides the
long-running loop for one process. On Unix/Linux, optional `WorkerPool` uses
`ext-pcntl` and `ext-posix` for fixed process concurrency; construct PDO,
Redis/Valkey, AMQP, SQS, and other process-bound resources inside its worker
factory after fork. External Supervisor, systemd, Docker, or Kubernetes remains
the preferred production supervisor when available.

## Delivery semantics

Durable transports provide at-least-once delivery. Terminal failures are
persisted before rejection, stale receipts cannot settle reclaimed work, and
telemetry failures cannot change queue or handler outcomes. Handlers that
produce durable side effects must remain idempotent.

Workflows use expiring dispatch claims and a durable `handled` reconciliation
state. Queue and workflow settlement can be atomic when DBLayer adapters share
one connection; cross-system compositions remain at-least-once.

## Future integrations

This release is feature-frozen. A following release may add optional NATS
JetStream and Kafka transports without changing Omnibus's role as the
application-level message bus:

- NATS JetStream through the broker boundary, mapping durable pull consumers,
  ACK/NAK, delayed redelivery, and stable message IDs.
- Kafka through a dedicated partition-aware transport with manual offset
  settlement, consumer-group rebalance handling, partition keys, and durable
  retry topics.
- Additional brokers only where their native delivery guarantees can satisfy
  Omnibus's explicit settlement contract.
- Expanded opt-in infrastructure tests for real MySQL/PostgreSQL replica lag,
  reconnect, and failover behavior.

These integrations will remain optional and will not add broker clients to the
core runtime dependencies. See [future integrations](docs/future-integrations.rst)
for the intended boundaries and acceptance criteria.

## Quality checks

```bash
composer ic:tests
composer ic:ci
composer benchmark
composer soak:consumer
composer soak:durable
composer soak:workflow
```

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/Omnibus/security/advisories/new).

Omnibus is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Omnibus/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/Omnibus/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/Omnibus/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/Omnibus/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/Omnibus/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/Omnibus/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/Omnibus/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>

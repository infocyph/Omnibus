Omnibus documentation
=====================

Omnibus is a framework-agnostic event bus and reliable message queue for PHP
8.4 and newer. It can be required and operated directly in any application; no
framework or command package is needed.

The same explicit message lifecycle covers synchronous commands, PSR-14 events,
queued listeners, delayed work, durable consumers, workflows, scheduling
adapters, and provider-neutral broadcasts.

.. toctree::
   :maxdepth: 2
   :caption: Guide

   getting-started
   architecture
   messages
   events
   queues
   backends
   serialization
   policies
   workflows
   scheduling-and-broadcasting
   operations
   integration
   testing
   performance
   release-checklist

Guarantee summary
-----------------

* Dispatch resolves only the selected route and transport.
* Durable transports provide at-least-once delivery.
* Settlement is conditional, so stale reservations cannot settle reclaimed
  work.
* Terminal failures are persisted before destructive rejection.
* Unknown or malformed payloads never instantiate payload-selected PHP classes.
* Optional DBLayer, CacheLayer, Redis, AMQP, SQS, broadcasting, workflow, and
  telemetry paths initialize only when applications construct them.
* Telemetry failures never alter enqueue, settlement, persistence, or handler
  outcomes.

Omnibus does not claim exactly-once side effects. Handlers that mutate durable
state must be idempotent.

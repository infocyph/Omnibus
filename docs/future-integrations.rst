Future integrations
===================

Release boundary
----------------

The current release is feature-frozen. Omnibus is an application-level event,
queue, policy, and workflow library; it does not replace an external messaging
service. Future broker work will let applications keep the Omnibus API while
selecting additional infrastructure underneath it.

No future adapter may add its client or extension to Omnibus's required
dependencies. Provider connections, credentials, topology, and process
lifecycle remain owned by the host application.

NATS JetStream
--------------

The planned NATS integration will target JetStream rather than non-durable Core
NATS. It should adapt the existing broker boundary with:

* acknowledged publication and stable message-ID headers;
* durable pull consumers and explicit per-message acknowledgement;
* negative acknowledgement and delayed redelivery;
* delivery-attempt metadata and pending-depth reporting;
* shared delivery fencing where required to prevent a stale worker from
  settling a newer redelivery.

Delayed initial publication may require a declared minimum NATS server version.
When the configured server cannot provide a capability, the adapter must fail
explicitly rather than emulate it silently.

Kafka
-----

Kafka is a partitioned event log rather than a conventional queue. Its planned
integration therefore requires a dedicated transport instead of treating an
offset as a generic broker receipt. The first version should provide:

* explicit consumer groups and rebalance handling;
* manual offset settlement only after application handling or durable failure
  persistence;
* topic, partition, offset, group, and generation-aware receipts;
* an explicit partition-key stamp with stable message-ID fallback;
* bounded retry topics or partition pause/seek behavior for redelivery;
* consumer-lag reporting as an inexact queue-depth measurement.

Initial consumption should permit only one outstanding record per assigned
partition. Partition-parallel processing must not commit past an unsettled
earlier offset. Kafka transactions may improve Kafka-to-Kafka retry movement,
but Omnibus will continue to describe cross-system processing as at-least-once.

Additional providers
--------------------

Other broker integrations may be proposed after NATS and Kafka. Each must use
the generic broker boundary when its semantics fit, or introduce a dedicated
transport when ordering, settlement, or replay semantics differ materially.
The roadmap does not promise a provider until its client maturity, operational
model, and delivery guarantees have been validated.

Exceptional database topology tests
-----------------------------------

Lagging read replicas are an exceptional deployment topology rather than a
requirement for ordinary Omnibus use. The current writer-affinity regression is
therefore opt-in and runs only when ``IC_SERVICE_REPLICA_DATABASE`` identifies
a deliberately stale database. The dedicated ``replica-affinity`` CI job runs
this deterministic check against empty MySQL, MariaDB, and PostgreSQL databases;
it does not represent physical replication or failover coverage.

A future infrastructure suite should add real replicated environments for:

* controlled MySQL asynchronous replication lag;
* controlled MariaDB asynchronous replication lag;
* controlled PostgreSQL streaming-replication lag;
* controlled SQL Server availability-group replica lag;
* writer and reader reconnect during queue and workflow settlement;
* primary failover while claims or reservations are outstanding;
* multiple readers at different replay positions;
* proof that queue reservation, receipt settlement, workflow locking,
  transitions, failure writes, and atomic workflow acknowledgement never make
  mutation decisions from a replica.

These tests should run in a dedicated opt-in or scheduled workflow because they
require privileged multi-node service orchestration. Their absence should be
reported as an environment skip, not as a failure of the normal package suite.

Acceptance criteria
-------------------

A future integration is releasable only when it:

* remains optional and lazy to initialize;
* preserves stable message identity independently of payload decoding;
* persists terminal failures before destructive settlement;
* documents ordering, retry, replay, delay, and depth semantics precisely;
* survives redelivery, stale receipt, crash, reconnect, and rebalance tests;
* passes live multi-worker integration and soak tests against supported server
  versions;
* includes exceptional topology tests separately when replicas or failover are
  part of the supported deployment model;
* keeps handlers responsible for idempotent durable side effects.

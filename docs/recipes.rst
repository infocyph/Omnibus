Real-world examples
===================

These recipes show composition without a framework or command package. Replace
application-owned services such as ``$mailer``, ``$logger``, and provider SDK
clients with objects from the host application.

The examples deliberately construct optional integrations only in the path that
uses them.

The whole scene
---------------

Consider an online shop that accepts an order, captures payment, reserves
inventory, sends mail, and updates a private browser channel:

.. code-block:: text

   HTTP checkout
       |
       | database transaction
       v
   order committed -- AfterCommitDispatcher --> MessageBus
                                              |
                       RouteMap + TransportRegistry
                                              |
                         DBLayer / Redis / AMQP / SQS
                                              |
                            bounded ConsumerTask
                                              |
              retry + uniqueness + overlap + deadline
                                              |
                              HandlerMap
                         /         |          \
                    workflow     PSR event    broadcast
                       |             |             |
                 chain / batch   listeners     provider SDK
                       |
                acknowledge or durable failure
                       |
                    telemetry

The web request constructs the producer path. The worker constructs the
consumer, handlers, execution policies, and failure store. Optional integrations
are absent until that path explicitly creates them.

Omnibus module map
------------------

.. list-table::
   :header-rows: 1

   * - Module
     - Real-life responsibility
     - Example
   * - ``MessageBus``, routing, handlers, and synchronous transport
     - Select where a command runs and which callable handles it.
     - :ref:`complete-sqlite-durable-queue`
   * - Envelopes and stamps
     - Carry message ID, route, delay, attempt, workflow, and policy metadata.
     - :ref:`message-and-serializer`
   * - Serialization
     - Persist allow-listed messages without decoding arbitrary PHP classes.
     - :ref:`message-and-serializer`
   * - Transports and consumers
     - Reserve, execute, retry, acknowledge, reject, or release bounded work.
     - :ref:`host-owned-worker-loop`
   * - DBLayer
     - Durable queue, failure store, and workflow state.
     - :ref:`complete-sqlite-durable-queue`
   * - Redis and Valkey
     - Atomic queue lifecycle and CacheLayer counter policies.
     - :ref:`redis-or-valkey-transport`
   * - AMQP and SQS
     - Adapt provider deliveries, receipts, delay, depth, and settlement.
     - :ref:`broker-provider-adapters`
   * - CacheLayer policies
     - Uniqueness, overlap, rate limits, and circuit breaking.
     - :ref:`execution-policies`
   * - Events and queued listeners
     - React synchronously or hand a listener to the queue.
     - :ref:`synchronous-and-queued-events`
   * - Workflows
     - Coordinate ordered chains and concurrent batches.
     - :ref:`durable-chains-and-batches`
   * - Scheduling
     - Turn a stable scheduler key into a fresh message.
     - :ref:`scheduling-and-broadcasting-recipe`
   * - Broadcasting and channel authorization
     - Publish bounded provider-neutral events to authorized channels.
     - :ref:`channel-authorization`
   * - After-commit and after-response dispatch
     - Align message timing with transaction and HTTP lifecycle boundaries.
     - :ref:`dispatch-timing`
   * - Telemetry
     - Observe delivery and execution without changing outcomes.
     - :ref:`telemetry-decorators`
   * - Failure management
     - Inspect, retry, forget, flush, and prune terminal work.
     - :ref:`failure-inspection-and-replay`
   * - Clocks, deadlines, and cancellation
     - Make time explicit and expose cooperative handler cancellation.
     - :ref:`cooperative-deadlines`
   * - Testing helpers and in-memory transport
     - Assert dispatch and lifecycle behavior without infrastructure.
     - :ref:`application-tests`

Recommended application split
-----------------------------

Keep composition explicit:

.. list-table::
   :header-rows: 1

   * - Bootstrap
     - Construct
     - Do not construct
   * - Web/API
     - Message codecs, selected producer senders, routes, and optional
       after-commit/after-response adapters.
     - Consumer loops, worker scopes, unused broker clients, or failure
       inspection services.
   * - Worker
     - Selected receiver, handlers, retry strategy, failure store, execution
       scopes, workflow decorators, and telemetry.
     - HTTP stack, session state, templates, or after-response runtime.
   * - Scheduler
     - ``MessageFactoryMap``, ``ScheduledMessageDispatcher``, and only the
       producer path needed by scheduled messages.
     - Worker loop or web runtime.
   * - Operations
     - Failure manager, queue-depth telemetry, retention, and recovery tools.
     - Business request handlers unrelated to the operation.

.. _message-and-serializer:

Message and serializer
----------------------

Durable transports require an explicit, allow-listed codec for every message.
Aliases are persisted data: keep them stable after deployment.

.. code-block:: php

   declare(strict_types=1);

   use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
   use Infocyph\Omnibus\Serialization\CoreStampCodecs;
   use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
   use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
   use Infocyph\Omnibus\Serialization\StampCodecRegistry;

   final readonly class SendReceipt
   {
       public function __construct(
           public string $invoiceId,
           public string $email,
       ) {}
   }

   $serializer = new JsonEnvelopeSerializer(
       new MessageCodecRegistry([
           new CallbackMessageCodec(
               'billing.send-receipt.v1',
               SendReceipt::class,
               static fn (SendReceipt $message): array => [
                   'invoice_id' => $message->invoiceId,
                   'email' => $message->email,
               ],
               static fn (array $data): SendReceipt => new SendReceipt(
                   (string) ($data['invoice_id'] ?? ''),
                   (string) ($data['email'] ?? ''),
               ),
           ),
       ]),
       new StampCodecRegistry(CoreStampCodecs::all()),
       maximumBytes: 262_144,
       maximumStamps: 64,
   );

Validate domain fields in the message constructor or decoder. Omnibus validates
the envelope structure and configured size limits; it cannot infer application
business rules.

An envelope adds transport metadata without modifying the domain message:

.. code-block:: php

   use Infocyph\Omnibus\Envelope\DelayStamp;
   use Infocyph\Omnibus\Envelope\Envelope;
   use Infocyph\Omnibus\Envelope\MessageIdStamp;

   $envelope = Envelope::wrap(
       new SendReceipt('invoice-42', 'owner@example.com'),
   )->with(new DelayStamp(10));

   $messageId = $envelope->last(MessageIdStamp::class)?->id;

The bus or durable transport adds a message ID when one is absent. Routes add
route and delay stamps. Receivers add attempt stamps. Applications ordinarily
do not create attempt, route, workflow, or uniqueness stamps themselves.

.. _complete-sqlite-durable-queue:

Complete SQLite durable queue
-----------------------------

Install DBLayer:

.. code-block:: console

   composer require infocyph/dblayer

Run the schema statements once in a migration or deployment step. Do not run
schema creation during request dispatch:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Connection\ConnectionConfig;
   use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;

   $connection = new Connection(ConnectionConfig::fromArray([
       'driver' => 'sqlite',
       'database' => __DIR__.'/../storage/queue.sqlite',
   ]));

   foreach (QueueSchema::statements('sqlite') as $statement) {
       $connection->statement($statement);
   }

Construct the producer and one bounded consumer operation:

.. code-block:: php

   use Infocyph\Omnibus\Clock\SystemClock;
   use Infocyph\Omnibus\Consumer\Consumer;
   use Infocyph\Omnibus\Failure\FailureManager;
   use Infocyph\Omnibus\Handler\HandlerMap;
   use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
   use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
   use Infocyph\Omnibus\MessageBus;
   use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
   use Infocyph\Omnibus\Routing\Route;
   use Infocyph\Omnibus\Routing\RouteMap;
   use Infocyph\Omnibus\Transport\TransportRegistry;

   $clock = new SystemClock();
   $queue = new DBLayerTransport($connection, $serializer, $clock);
   $failureStore = new DBLayerFailureStore($connection, $serializer);

   $handlers = new HandlerMap([
       SendReceipt::class => static function (SendReceipt $message) use ($mailer): void {
           $mailer->sendReceipt($message->invoiceId, $message->email);
       },
   ]);

   $bus = new MessageBus(
       new RouteMap([
           SendReceipt::class => new Route(
               transport: 'database',
               queue: 'mail',
           ),
       ]),
       new TransportRegistry([
           'database' => $queue,
       ]),
   );

   $consumer = new Consumer(
       $queue,
       $handlers,
       new ExponentialRetryStrategy(
           maximumAttempts: 5,
           initialDelaySeconds: 1,
           multiplier: 2,
           maximumDelaySeconds: 60,
           jitterRatio: 0.2,
       ),
       $failureStore,
       $clock,
   );

   $bus->dispatch(new SendReceipt('invoice-42', 'owner@example.com'));

   $result = $consumer->run(
       queue: 'mail',
       limit: 25,
       visibilitySeconds: 90,
   );

   $failures = new FailureManager($failureStore);

Use a file path on reliable local storage. SQLite is a single-host option; use
MySQL or PostgreSQL when several hosts consume the same queue.

.. _redis-or-valkey-transport:

Redis or Valkey transport
-------------------------

The native adapter uses the small ``RedisClient`` contract. With phpredis:

.. code-block:: php

   use Infocyph\Omnibus\Clock\SystemClock;
   use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
   use Infocyph\Omnibus\Integration\Redis\RedisTransport;

   $redis = new Redis();
   $redis->connect('127.0.0.1', 6379, 2.0);

   $redisClient = new CallbackRedisClient(
       static fn (string $command, string ...$arguments): mixed =>
           $redis->rawCommand($command, ...$arguments),
   );

   $queue = new RedisTransport(
       $redisClient,
       $serializer,
       new SystemClock(),
       prefix: 'acme-billing',
   );

Use this ``$queue`` in the same ``MessageBus`` and ``Consumer`` composition as
the database example. Create the Redis client only in applications that select
the Redis transport.

.. _broker-provider-adapters:

Broker provider adapters
------------------------

Omnibus avoids forcing an AMQP or AWS SDK on every installation. A provider
adapter translates the selected SDK into ``AmqpBackend`` or ``SqsBackend``.
This SQS-shaped template shows the complete boundary; replace the illustrative
client method names with the selected SDK calls:

.. code-block:: php

   use Infocyph\Omnibus\Integration\Broker\BrokerCapabilities;
   use Infocyph\Omnibus\Integration\Broker\BrokerDelivery;
   use Infocyph\Omnibus\Integration\SQS\SqsBackend;
   use Infocyph\Omnibus\Integration\SQS\SqsTransport;

   final readonly class ApplicationSqsBackend implements SqsBackend
   {
       public function __construct(private object $client) {}

       public function capabilities(): BrokerCapabilities
       {
           return new BrokerCapabilities(
               delayedDelivery: true,
               delayedRelease: true,
               exactSize: false,
               maximumBatch: 10,
           );
       }

       public function send(
           string $queue,
           string $messageId,
           string $payload,
           float $delaySeconds,
       ): void {
           $this->client->publish($queue, $payload, [
               'message_id' => $messageId,
               'delay_seconds' => $delaySeconds,
           ]);
       }

       public function receive(
           string $queue,
           int $limit,
           float $visibilitySeconds,
       ): array {
           return array_map(
               static fn (object $delivery): BrokerDelivery =>
                   new BrokerDelivery(
                       receipt: $delivery->receipt,
                       payload: $delivery->body,
                       attempt: max(1, $delivery->receiveCount),
                   ),
               $this->client->reserve($queue, $limit, $visibilitySeconds),
           );
       }

       public function acknowledge(string $queue, string $receipt): void
       {
           $this->client->delete($queue, $receipt);
       }

       public function reject(string $queue, string $receipt): void
       {
           $this->client->deadLetter($queue, $receipt);
       }

       public function release(
           string $queue,
           string $receipt,
           float $delaySeconds,
       ): void {
           $this->client->changeVisibility($queue, $receipt, $delaySeconds);
       }

       public function size(string $queue): int
       {
           return $this->client->approximateDepth($queue);
       }
   }

   $queue = new SqsTransport(
       new ApplicationSqsBackend($providerClient),
       $serializer,
   );

An AMQP adapter implements the same methods through ``AmqpBackend`` and returns
capabilities matching its declared exchange, delay plugin, dead-letter
topology, and prefetch configuration. Never advertise delayed delivery or exact
depth unless the concrete topology actually provides it.

.. _memcached-uniqueness-with-a-durable-database-queue:

Memcached uniqueness with a durable database queue
--------------------------------------------------

Memcached can coordinate policies but is not durable message storage. Combine a
Memcached lease provider with DBLayer:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
   use Infocyph\Omnibus\Envelope\Envelope;
   use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;
   use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
   use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;

   $memcached = new Memcached();
   $memcached->addServer('127.0.0.1', 11211);

   $locks = new DetachedLeaseAdapter(
       new MemcachedLockProvider($memcached, 'acme-billing:locks:'),
   );

   // $queue is the DBLayerTransport from the durable example.
   $leasedQueue = new UniqueTransport($queue, $locks);
   $uniqueProducer = new UniqueSender(
       $leasedQueue,
       $locks,
       static function (Envelope $envelope): string {
           $message = $envelope->message;
           if (!$message instanceof SendReceipt) {
               throw new LogicException('Unexpected unique message type.');
           }

           return 'receipt:'.$message->invoiceId;
       },
       leaseSeconds: 300,
   );

Register ``$uniqueProducer`` in the producer's ``TransportRegistry`` and pass
``$leasedQueue`` to the consumer. The producer adds the lease stamp; settlement
through ``$leasedQueue`` releases it. A retry refreshes the lease for its
original duration plus the retry delay.

.. _execution-policies:

Execution policies
------------------

Compose execution scopes once at worker bootstrap. Memcached locks can protect
overlap; CacheLayer's bundled atomic counters currently use Redis or Valkey:

.. code-block:: php

   use Infocyph\CacheLayer\Counter\AtomicCounters;
   use Infocyph\Omnibus\Consumer\DirectExecutionScope;
   use Infocyph\Omnibus\Envelope\Envelope;
   use Infocyph\Omnibus\Integration\CacheLayer\CircuitBreakerScope;
   use Infocyph\Omnibus\Integration\CacheLayer\FixedWindowRateLimitScope;
   use Infocyph\Omnibus\Integration\CacheLayer\OverlapProtectionScope;

   $scope = new OverlapProtectionScope(
       new DirectExecutionScope(),
       $locks,
       static fn (Envelope $envelope): string =>
           'account:'.$envelope->message->accountId,
       leaseSeconds: 120,
   );

   $counters = AtomicCounters::redis(
       namespace: 'acme-billing',
       client: $redis,
   );

   $scope = new FixedWindowRateLimitScope(
       $scope,
       $counters,
       $clock,
       static fn (Envelope $envelope): string =>
           'tenant:'.$envelope->message->tenantId,
       maximum: 100,
       windowSeconds: 60,
   );

   $scope = new CircuitBreakerScope(
       $scope,
       $counters,
       $locks,
       $clock,
       static fn (Envelope $envelope): string => 'provider:billing',
       failureThreshold: 5,
       recoverySeconds: 30,
       failureWindowSeconds: 60,
   );

   $consumer = new Consumer(
       $queue,
       $handlers,
       $retryStrategy,
       $failureStore,
       $clock,
       $scope,
   );

Every key callback returns a logical application identifier. Omnibus hashes it
into a bounded backend-safe key. If Redis is unavailable, uniqueness and
overlap can still use Memcached, while rate limiting and circuit breaking need
an application-supplied ``AtomicCounterStoreInterface`` implementation with
atomic TTL behavior.

.. _synchronous-and-queued-events:

Synchronous and queued events
-----------------------------

Synchronous PSR-14 listeners use an explicit map:

.. code-block:: php

   use Infocyph\Omnibus\Event\EventDispatcher;
   use Infocyph\Omnibus\Event\ListenerMap;

   final readonly class InvoicePaid
   {
       public function __construct(public string $invoiceId) {}
   }

   $events = new EventDispatcher(new ListenerMap([
       InvoicePaid::class => [
           static fn (InvoicePaid $event) => $audit->record(
               'invoice.paid',
               $event->invoiceId,
           ),
           static fn (InvoicePaid $event) => $metrics->increment('invoice.paid'),
       ],
   ]));

   $events->dispatch(new InvoicePaid('invoice-42'));

A queued-listener marker is handed to the message bus instead of running in the
emitter:

.. code-block:: php

   use Infocyph\Omnibus\Event\QueuedListener;
   use Infocyph\Omnibus\Event\QueuedListenerHandler;
   use Infocyph\Omnibus\Event\QueuedListenerResolver;
   use Infocyph\Omnibus\Event\ShouldQueue;

   final class EmailReceiptListener implements ShouldQueue {}

   $resolver = new QueuedListenerResolver([
       EmailReceiptListener::class =>
           static fn (InvoicePaid $event) => $mailer->sendPaidNotice($event->invoiceId),
   ]);

   $handlers = new HandlerMap([
       QueuedListener::class => new QueuedListenerHandler($resolver),
   ]);

   $events = new EventDispatcher(
       new ListenerMap([
           InvoicePaid::class => [new EmailReceiptListener()],
       ]),
       $bus,
   );

Add explicit codecs for ``QueuedListener`` and ``InvoicePaid`` when the selected
route is durable. Route ``QueuedListener::class`` to the desired queue and
register its handler only in the worker path.

.. _durable-chains-and-batches:

Durable chains and batches
--------------------------

Use the same durable queue, failure store, serializer, and database connection:

.. code-block:: php

   use Infocyph\Omnibus\Consumer\DirectExecutionScope;
   use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
   use Infocyph\Omnibus\Workflow\BatchCancellationScope;
   use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
   use Infocyph\Omnibus\Workflow\WorkflowFailureStore;
   use Infocyph\Omnibus\Workflow\WorkflowTransport;

   $workflowStore = new DBLayerWorkflowStore($connection, $serializer);
   $coordinator = new WorkflowCoordinator(
       $workflowStore,
       $queue,
       $events,
   );

   $workflowQueue = new WorkflowTransport($queue, $coordinator);
   $workflowFailures = new WorkflowFailureStore($failureStore, $coordinator);
   $workflowScope = new BatchCancellationScope(
       new DirectExecutionScope(),
       $workflowStore,
   );

   $chainId = $coordinator->chain([
       new ReserveInventory('order-42'),
       new CapturePayment('order-42'),
       new DispatchShipment('order-42'),
   ], queue: 'orders');

   $batchId = $coordinator->batch([
       new ResizeImage('original.jpg', 320),
       new ResizeImage('original.jpg', 640),
       new ResizeImage('original.jpg', 1280),
   ], queue: 'images');

   $coordinator->cancel($batchId);

Pass ``$workflowQueue``, ``$workflowFailures``, and ``$workflowScope`` to the
consumer. Register codecs for every workflow message. Successful
acknowledgement advances state; terminal failure updates state through the
failure-store decorator.

.. _host-owned-worker-loop:

Host-owned worker loop
----------------------

``ConsumerTask`` performs one bounded operation. A plain PHP host may own the
loop and its stopping policy:

.. code-block:: php

   use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
   use Infocyph\Omnibus\Consumer\Command\ConsumerTask;

   $task = new ConsumerTask($consumer);
   $request = new ConsumeRequest(
       queue: 'mail',
       limit: 25,
       visibilitySeconds: 90,
   );

   $startedAt = hrtime(true);
   $handled = 0;

   while (true) {
       $result = $task->run($request);
       $handled += $result->received;

       $runtimeSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
       if ($handled >= 10_000 || $runtimeSeconds >= 3_600) {
           break;
       }

       if ($result->received === 0) {
           usleep(100_000);
       }
   }

The host should also stop on its platform's shutdown signal and memory policy.
Set visibility longer than ordinary execution. Process supervision, restart
backoff, and horizontal scaling remain outside Omnibus.

.. _failure-inspection-and-replay:

Failure inspection and replay
-----------------------------

.. code-block:: php

   use Infocyph\Omnibus\Failure\FailureManager;

   $manager = new FailureManager($failureStore);

   foreach ($failureStore->all(limit: 50) as $failure) {
       printf(
           "%s queue=%s attempt=%d failure=%s reason=%s\n",
           $failure->id,
           $failure->queue,
           $failure->attempt,
           $failure->failureClass,
           $failure->reason,
       );
   }

   // Removes the failure only after the sender accepts the envelope.
   $manager->retry($failureId, $queue);

   // Application-owned retention policy.
   $manager->prune(new DateTimeImmutable('-30 days'));

Raw poison payloads cannot be retried until an appropriate codec is restored.
Do not log payloads blindly; they may contain sensitive application data.

.. _dispatch-timing:

Dispatch timing: after commit and after response
------------------------------------------------

Avoid sending a message for a transaction that later rolls back:

.. code-block:: php

   use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;

   $afterCommit = new AfterCommitDispatcher($connection, $bus);

   $connection->transaction(function () use ($connection, $afterCommit): void {
       $connection->insert(
           'INSERT INTO invoices (id, status) VALUES (?, ?)',
           ['invoice-42', 'paid'],
       );

       $afterCommit->dispatch(
           new SendReceipt('invoice-42', 'owner@example.com'),
       );
   });

Dispatch occurs after the outermost successful commit. A rollback discards the
registered dispatch.

After-response dispatch is for work that may begin only after the host has
finished the response. The host owns the runtime adapter:

.. code-block:: php

   use Infocyph\Omnibus\Dispatch\AfterResponseDispatcher;
   use Infocyph\Omnibus\Dispatch\AfterResponseRuntime;

   final class FpmAfterResponseRuntime implements AfterResponseRuntime
   {
       public function defer(callable $callback): void
       {
           register_shutdown_function(static function () use ($callback): void {
               if (function_exists('fastcgi_finish_request')) {
                   fastcgi_finish_request();
               }

               $callback();
           });
       }
   }

   $afterResponse = new AfterResponseDispatcher(
       $bus,
       new FpmAfterResponseRuntime(),
   );

   $afterResponse->dispatch(
       new RecordPageView($requestId, $path),
   );

The callback still runs in the web process. Use it for optional short work.
Dispatch to a durable transport before returning when the work must survive a
web-process crash.

.. _scheduling-and-broadcasting-recipe:

Scheduling and broadcasting
----------------------------

The host scheduler stores only stable factory keys:

.. code-block:: php

   use Infocyph\Omnibus\Scheduling\MessageFactoryMap;
   use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;

   $scheduled = new ScheduledMessageDispatcher(
       new MessageFactoryMap([
           'billing.close-period' =>
               static fn (): CloseBillingPeriod => new CloseBillingPeriod(
                   new DateTimeImmutable('first day of this month 00:00:00'),
               ),
       ]),
       $bus,
   );

   $scheduled->dispatch('billing.close-period');

Adapt a broadcasting provider with one callback:

.. code-block:: php

   use Infocyph\Omnibus\Broadcasting\Broadcast;
   use Infocyph\Omnibus\Broadcasting\BroadcastHandler;
   use Infocyph\Omnibus\Broadcasting\CallbackBroadcaster;
   use Infocyph\Omnibus\Broadcasting\Channel;

   $broadcaster = new CallbackBroadcaster(
       static fn (Broadcast $message) => $provider->publish(
           $message->event,
           $message->channels,
           $message->payload,
       ),
   );

   $handler = new BroadcastHandler($broadcaster);
   $handler(new Broadcast(
       'invoice.paid',
       [new Channel('account.42', private: true)],
       ['invoice_id' => 'invoice-42'],
   ));

.. _channel-authorization:

Channel authorization
---------------------

Authorization stays in the host application because Omnibus does not own users,
authentication, or sessions:

.. code-block:: php

   use Infocyph\Omnibus\Broadcasting\Channel;
   use Infocyph\Omnibus\Broadcasting\ChannelAuthorizer;

   final readonly class AccountChannelAuthorizer implements ChannelAuthorizer
   {
       public function allows(object $principal, Channel $channel): bool
       {
           if (!$principal instanceof AuthenticatedUser) {
               return false;
           }

           $accountId = str_starts_with($channel->name, 'account.')
               ? substr($channel->name, strlen('account.'))
               : null;

           return $accountId !== null
               && $principal->canAccessAccount($accountId);
       }
   }

   $channel = new Channel('account.42', private: true);
   if (!(new AccountChannelAuthorizer())->allows($user, $channel)) {
       throw new RuntimeException('Channel access denied.');
   }

Validate authorization before returning provider subscription credentials.
Presence channels must be private.

.. _telemetry-decorators:

Telemetry decorators
--------------------

Telemetry is opt-in and best-effort:

.. code-block:: php

   use Infocyph\Omnibus\Consumer\DirectExecutionScope;
   use Infocyph\Omnibus\Telemetry\ObservedExecutionScope;
   use Infocyph\Omnibus\Telemetry\ObservedFailureStore;
   use Infocyph\Omnibus\Telemetry\ObservedTransport;
   use Infocyph\Omnibus\Telemetry\TelemetrySink;

   $telemetry = new class($metrics) implements TelemetrySink {
       public function __construct(private object $metrics) {}

       public function record(
           string $metric,
           float|int $value,
           array $attributes = [],
       ): void {
           $this->metrics->record($metric, $value, $attributes);
       }
   };

   $observedQueue = new ObservedTransport(
       $queue,
       $telemetry,
       $clock,
       transport: 'database',
   );
   $observedFailures = new ObservedFailureStore($failureStore, $telemetry);
   $observedScope = new ObservedExecutionScope(
       new DirectExecutionScope(),
       $telemetry,
   );

Pass the three decorators to ``Consumer``. Exporter exceptions are swallowed so
observability cannot change enqueue, settlement, persistence, or handler
outcomes. Keep metric attributes bounded and low-cardinality.

.. _cooperative-deadlines:

Cooperative deadlines
---------------------

``DeadlineExecutionScope`` exposes a cancellation token to a handler and checks
it again when the handler returns:

.. code-block:: php

   use Infocyph\Omnibus\Consumer\CancellationStamp;
   use Infocyph\Omnibus\Consumer\DeadlineExecutionScope;
   use Infocyph\Omnibus\Consumer\DirectExecutionScope;
   use Infocyph\Omnibus\Envelope\Envelope;

   $deadlineScope = new DeadlineExecutionScope(
       new DirectExecutionScope(),
       $clock,
       timeoutSeconds: 30,
   );

   $handlers = new HandlerMap([
       ImportCustomers::class => static function (
           ImportCustomers $message,
           Envelope $envelope,
       ) use ($importer): void {
           $token = $envelope->last(CancellationStamp::class)?->token;

           foreach ($importer->rows($message->file) as $row) {
               $token?->throwIfCancellationRequested();
               $importer->persist($row);
           }
       },
   ]);

Pass ``$deadlineScope`` to ``Consumer``. This is cooperative cancellation: it
cannot interrupt a blocking database driver, socket, extension call, or
non-cooperative provider SDK. The worker host must enforce a larger hard
process timeout.

.. _application-tests:

Application tests
-----------------

Use ``RecordingSender`` when only dispatch matters:

.. code-block:: php

   use Infocyph\Omnibus\MessageBus;
   use Infocyph\Omnibus\Routing\Route;
   use Infocyph\Omnibus\Routing\RouteMap;
   use Infocyph\Omnibus\Testing\RecordingSender;
   use Infocyph\Omnibus\Transport\TransportRegistry;

   $sender = new RecordingSender();
   $bus = new MessageBus(
       new RouteMap([
           SendReceipt::class => new Route('recording', 'mail'),
       ]),
       new TransportRegistry([
           'recording' => $sender,
       ]),
   );

   $bus->dispatch(new SendReceipt('invoice-42', 'owner@example.com'));

   assert($sender->count(SendReceipt::class) === 1);
   assert($sender->sent()[0]['queue'] === 'mail');

Use ``InMemoryTransport`` when reservation, visibility, retry, settlement, or
consumer behavior matters:

.. code-block:: php

   use Infocyph\Omnibus\Clock\SystemClock;
   use Infocyph\Omnibus\Transport\InMemoryTransport;

   $transport = new InMemoryTransport(new SystemClock());
   $transport->send(
       Envelope::wrap(new SendReceipt('invoice-42', 'owner@example.com')),
       'mail',
   );

   $reservation = [...$transport->receive('mail')][0];
   $transport->acknowledge($reservation);

   assert($transport->size('mail') === 0);

These helpers are process-local and deterministic. Keep separate service tests
for the selected DBLayer, Redis, Memcached, AMQP, or SQS adapter because an
in-memory test cannot prove provider settlement semantics.

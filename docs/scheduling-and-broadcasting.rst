Scheduling and broadcasting
============================

Scheduled dispatch
------------------

``MessageFactoryMap`` maps a stable key to a zero-argument message factory.
``ScheduledMessageDispatcher`` resolves that key and dispatches the resulting
message through ``MessageBus``.

.. code-block:: php

   $factories = new MessageFactoryMap([
       'billing.close-period' => static fn (): CloseBillingPeriod =>
           new CloseBillingPeriod($periodClock->current()),
   ]);

   $scheduled = new ScheduledMessageDispatcher($factories, $bus);
   $scheduled->dispatch('billing.close-period');

Only the stable key belongs in a cron table or compiled schedule. Do not
serialize closures, service objects, or constructed mutable messages into a
schedule manifest.

Omnibus does not implement cron parsing, timers, process loops, mutex
orchestration, or process supervision. Any host scheduler can invoke the
standalone dispatcher.

Broadcasting
------------

``Broadcast`` contains a bounded event name, one through 1000 ``Channel``
objects, and a string-keyed payload. Event and channel names reject ASCII
control characters. Presence channels must also be private.

``Broadcaster`` is the provider boundary. ``CallbackBroadcaster`` adapts a
provider SDK without introducing a hard dependency:

.. code-block:: php

   $broadcaster = new CallbackBroadcaster(
       static function (Broadcast $broadcast) use ($provider): void {
           $provider->publish(
               $broadcast->event,
               $broadcast->channels,
               $broadcast->payload,
           );
       },
   );

``ChannelAuthorizer`` receives the application principal as an argument.
Omnibus does not resolve authentication, sessions, or provider credentials.
Provider clients initialize only when the application constructs a broadcast
path.

See :ref:`scheduling-and-broadcasting-recipe` for complete scheduler and
provider-callback examples.

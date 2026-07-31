Events and queued listeners
===========================

Synchronous events
------------------

``EventDispatcher`` implements PSR-14:

* listeners execute synchronously in configured order;
* listener return values are ignored;
* exceptions propagate to the emitter;
* a stoppable event is checked before each listener.

``ListenerMap`` uses explicit class/interface-to-listener mappings. It performs
no runtime discovery.

Queued listeners
----------------

A listener object implementing ``ShouldQueue`` is not invoked synchronously.
The dispatcher sends a ``QueuedListener`` message through its configured
``MessageBus``. If no bus is configured, dispatch fails explicitly with
``QueuedListenerNotConfigured``.

The queued-listener class and event payload must have explicit serializer
codecs before a durable transport can accept them. ``QueuedListenerResolver``
maps allowed listener classes to application callables when a consumer handles
the queued message.

Use a direct method call when the caller requires a result, or when the
operation is mandatory validation, authorization, persistence, or transaction
control. Events are appropriate for independently meaningful reactions to an
already successful state change.

See :ref:`synchronous-and-queued-events` for complete synchronous and queued
listener composition.

Chains, batches, and cancellation
=================================

``WorkflowCoordinator`` accepts message objects or envelopes, never closures.
Every durable workflow message needs an explicit serializer codec.

Chains
------

A chain exposes only its next pending item. Success exposes the following item.
A terminal failure marks the chain failed and cancels later pending items.
Lifecycle events are ``ChainCompleted`` and ``ChainFailed``.

Batches
-------

A batch exposes a bounded set of pending items, tracks succeeded, failed, and
cancelled counts, and supports cancellation. Lifecycle events are
``BatchCompleted``, ``BatchFailed``, and ``BatchFinalized``.

``BatchFinalized`` means every item reached a terminal state; it does not mean
every item succeeded.

Idempotent transitions
----------------------

Workflow stores return ``WorkflowTransition`` with current ``state`` and a
``changed`` flag. Repeated success, failure, cancellation, acknowledgement, or
failure-record callbacks do not regress terminal state or emit duplicate
lifecycle events.

A completed workflow never becomes failed or cancelled. Cancellation applies
to pending work. Already dispatched work can still arrive, so wrap handler
execution with ``BatchCancellationScope``. The scope checks both batch and
chain stamps and raises non-retryable ``WorkflowCancelled`` before the handler.

Transport composition
---------------------

Wrap the selected queue transport in ``WorkflowTransport`` so successful
acknowledgements advance workflow state. Wrap the selected failure store in
``WorkflowFailureStore`` so terminal failures update the workflow.

.. code-block:: php

   $transport = new WorkflowTransport($innerTransport, $coordinator);
   $failures = new WorkflowFailureStore($innerFailureStore, $coordinator);

Durable store
-------------

``DBLayerWorkflowStore`` persists workflow counters and encoded items in tables
created by ``QueueSchema``. The item-state and counter updates are conditional,
so repeated settlement is a no-op.

``dispatchPending()`` sends before marking an item dispatched. If a broker
accepts the message but the state update fails, recovery may send a duplicate.
This is the unavoidable at-least-once boundary between two independent durable
systems. Restore connectivity and call ``dispatchPending($workflowId)`` again;
handlers must remain idempotent.

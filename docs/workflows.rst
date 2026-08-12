Chains, batches, and reconciliation
===================================

``WorkflowCoordinator`` accepts message objects or envelopes, never closures.
Every durable workflow message needs an explicit serializer codec. Chains and
batches contain between 1 and 1000 items; collection stops and throws when an
iterable yields item 1001.

Item lifecycle
--------------

Workflow item state has one meaning at each boundary:

``pending``
   Eligible for a dispatch claim.
``dispatching``
   Owned by a coordinator claim; send is not yet confirmed.
``dispatched``
   Accepted by the sender and eligible for handler execution.
``handled``
   The handler returned successfully, but queue settlement and workflow
   finalization are not both confirmed.
``succeeded``
   Settlement and the workflow transition are complete.
``failed``
   A terminal handler or policy failure was recorded.
``cancelled``
   The item must not execute.

``WorkflowExecutionScope`` performs the item-aware execution check. It executes
only ``dispatched`` items and marks them ``handled`` after the handler returns.
Redelivered ``handled``, ``succeeded``, ``failed``, or ``cancelled`` items skip
business code. A delivery for ``pending`` or ``dispatching`` is a
``WorkflowInconsistentDelivery``.

Dispatch claims
---------------

The store atomically changes eligible items from ``pending`` to
``dispatching`` and returns a token and expiry. A successful send confirms that
token and changes the item to ``dispatched``. A send error releases the current
token to ``pending``. Stale tokens cannot confirm or release a newer claim, and
expired claims can be reclaimed. A chain claims one item; a batch is dispatched
in bounded chunks of 100 until all of its maximum 1000 items are sent.

Settlement boundary
-------------------

Compose the execution scope, transport, and failure store together:

.. code-block:: php

   $scope = new WorkflowExecutionScope($innerScope, $workflowStore);
   $transport = new WorkflowTransport($innerTransport, $coordinator);
   $failures = new WorkflowFailureStore($innerFailureStore, $coordinator);

Successful handling persists ``handled`` before acknowledgement.
``WorkflowTransport`` then acknowledges and finalizes ``handled`` to
``succeeded``. This ordering makes an ambiguous acknowledgement recoverable:
a redelivery skips business code and retries settlement.

When ``DBLayerTransport`` and ``DBLayerWorkflowStore`` share the exact DBLayer
connection, acknowledgement plus workflow finalization runs in one database
transaction. With Redis, AMQP, or SQS and a database workflow store, no
cross-system transaction exists. The ``handled`` marker narrows that unavoidable
at-least-once boundary; Omnibus does not claim exactly-once processing.

Chains, batches, and cancellation
---------------------------------

A chain claims only its next item. Success exposes the following item. Terminal
failure marks the chain failed and cancels later pending items. A batch allows
unrelated already-dispatched items to finish after another item fails. Batch
cancellation changes pending items to ``cancelled``; the execution scope also
prevents a cancelled delivered item from running.

Stores return ``WorkflowTransition`` facts describing which boundary was
crossed now. These facts drive ``ChainCompleted``, ``ChainFailed``,
``BatchCompleted``, ``BatchFailed``, ``BatchFinalized``, and
``WorkflowCancelled`` once. Conditional item transitions and aggregate locking
prevent terminal regression and counters exceeding the total.

Recovery
--------

After a coordinator crash, wait for dispatch claims to expire and call
``dispatchPending($workflowId)``. After an ambiguous queue settlement, redeliver
or explicitly reconcile the ``handled`` item through the workflow transport.
Generic ``FailureManager::retry()`` refuses ``ChainStamp`` and ``BatchStamp``;
workflow recovery must preserve store state and claim semantics.

See :ref:`durable-chains-and-batches` for a complete durable composition.

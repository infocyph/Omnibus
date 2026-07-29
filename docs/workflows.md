# Chains, batches, and cancellation

`WorkflowCoordinator` accepts named message objects/envelopes, never closures.
Every message must have an explicit serializer codec before a durable workflow
store can persist it.

A chain exposes only its next pending item. Success makes the next item pending;
a terminal failure marks the chain failed and cancels every later pending item.
`ChainCompleted` and `ChainFailed` are named events.

A batch exposes bounded pending items, tracks succeeded/failed/cancelled counts,
and supports cancellation. `BatchCancellationScope` prevents a cancelled
delivery from invoking its handler and raises the non-retryable
`WorkflowCancelled` failure. Named events are `BatchCompleted`, `BatchFailed`,
and `BatchFinalized`.

Use `WorkflowTransport` around the selected queue transport so successful
acknowledgements advance workflow state. Use `WorkflowFailureStore` around the
selected failure store so terminal failures stop/update workflows.

`DBLayerWorkflowStore` persists state and encoded items in the tables created by
`QueueSchema`. Dispatch occurs before an item is marked dispatched. If the
broker accepted a message but the state update failed, `dispatchPending()` may
send a duplicate during recovery. This preserves at-least-once recovery and
requires idempotent handlers. Re-run `dispatchPending($workflowId)` after
connectivity is restored.

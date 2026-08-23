Yes. Here is the **complete Omnibus change draft**, consolidating the earlier 15 points into one implementation list.

The current reason for the work is very specific: `Consumer` invokes handlers directly inside its execution scope, while `SyncTransport` has its own direct handler invocation path.   We want one reusable middleware-capable handler execution layer without turning Omnibus into a framework.

# Omnibus 2.3 — Complete Change Draft

## 1. Add `HandlerContext`

Add:

```text
src/Handler/HandlerContext.php
```

Recommended immutable context:

```php
final readonly class HandlerContext
{
    public function __construct(
        public string $queue,
        public int $attempt = 1,
        public bool $asynchronous = false,
    ) {}
}
```

Validate:

* queue must not be empty;
* attempt must be `>= 1`.

Do **not** add framework/service state such as container, logger, database, cache, request or application.

This is delivery/execution metadata only.

---

## 2. Add `HandlerMiddleware`

Add:

```text
src/Handler/HandlerMiddleware.php
```

Contract:

```php
interface HandlerMiddleware
{
    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed;
}
```

Purpose:

* tracing;
* metrics;
* execution timing;
* idempotency;
* locking;
* rate limiting;
* tenant/context propagation;
* custom lifecycle hooks;
* later Foundation `JobMiddleware`.

Keep the name **HandlerMiddleware**, not `JobMiddleware`.

---

## 3. Add `HandlerInvoker`

Add:

```text
src/Handler/HandlerInvoker.php
```

This becomes the single handler-execution component:

```text
HandlerMap
   ↓
HandlerInvoker
   ↓
middleware chain
   ↓
terminal handler
```

Constructor:

```php
public function __construct(
    HandlerMap $handlers,
    iterable $middleware = [],
)
```

Public execution:

```php
public function invoke(
    object $message,
    Envelope $envelope,
    HandlerContext $context,
): mixed
```

Responsibilities only:

* resolve handler;
* execute middleware;
* invoke terminal handler;
* return result.

It must not own:

* retry;
* failure persistence;
* queue acknowledgement;
* rejection;
* release;
* serialization;
* transport routing;
* worker lifecycle.

---

## 4. Preserve `HandlerMap` as resolver only

Do not inflate `HandlerMap`.

It should continue doing exactly what it currently does:

* concrete class lookup;
* parent-class matching;
* interface matching;
* ambiguity detection;
* resolved handler caching.

`HandlerInvoker` composes `HandlerMap`; it does not replace its resolution logic.

---

## 5. Preserve existing handler callable compatibility

This needs special care.

Currently:

### Consumer

invokes approximately:

```php
$handler($message, $envelope);
```

### SyncTransport

invokes:

```php
$handler($message, $envelope, $queue);
```

Do **not casually standardize this by changing existing callable arguments**, because that could create an unnecessary BC break.

For Omnibus 2.3, `HandlerInvoker` should preserve existing terminal behavior:

```text
asynchronous=true
    → handler(message, envelope)

asynchronous=false
    → handler(message, envelope, queue)
```

The **middleware API** becomes normalized through `HandlerContext`, while existing terminal callables keep their present behavior.

We can consider stricter unified handler signatures only in a future major version.

---

## 6. Normalize middleware execution across sync + async

Although the terminal callable compatibility stays intact, middleware behavior should be identical.

Both paths use:

```text
HandlerInvoker
```

So:

```text
SyncTransport
    ↓
same middleware chain
    ↓
handler

Consumer
    ↓
same middleware chain
    ↓
handler
```

That is the important normalization.

---

## 7. Update `Consumer`

Replace direct `HandlerMap` invocation with `HandlerInvoker`.

Current conceptual path:

```text
receive
→ decode
→ ExecutionScope
→ HandlerMap::for()
→ handler
→ acknowledge/retry/failure
```

New:

```text
receive
→ decode
→ ExecutionScope
→ HandlerInvoker
    → middleware
    → handler
→ acknowledge/retry/failure
```

Construct context from reservation:

```php
new HandlerContext(
    queue: $reservation->queue,
    attempt: $reservation->attempt,
    asynchronous: true,
)
```

### Important

Middleware executes **inside `ExecutionScope`**.

Keep:

```text
ExecutionScope
    └── HandlerInvoker
          └── Middleware
                └── Handler
```

not the reverse.

The existing `ExecutionScope` remains conceptually separate from handler middleware.

---

## 8. Middleware exceptions use normal Consumer failure semantics

No special middleware-error system.

If middleware throws:

```text
middleware exception
        ↓
Consumer catch
        ↓
RetryStrategy
   ↙             ↘
release         exhausted
                    ↓
               FailureStore
```

Therefore:

* retriable middleware exceptions are retried;
* exhausted middleware exceptions are persisted;
* acknowledgements occur only after the complete pipeline succeeds.

This means middleware behaves exactly like part of message handling.

---

## 9. Update `SyncTransport`

Change it from direct `HandlerMap` execution to `HandlerInvoker`.

Current implementation directly resolves and invokes the handler.

New context:

```php
new HandlerContext(
    queue: $queue,
    attempt: 1,
    asynchronous: false,
)
```

Then:

```php
$result = $this->invoker->invoke(
    $envelope->message,
    $envelope,
    $context,
);

return $envelope->with(
    new HandledStamp($result),
);
```

Keep existing:

```text
positive DelayStamp
→ UnsupportedDelay
```

behavior unchanged.

---

## 10. Middleware ordering must be deterministic

Given:

```php
[
    $a,
    $b,
    $c,
]
```

execution must be:

```text
A before
    B before
        C before
            handler
        C after
    B after
A after
```

Use declaration order.

Do not introduce:

* priorities;
* numeric weighting;
* automatic sorting;
* annotation/attribute discovery.

Simple ordered middleware is more predictable and faster.

---

## 11. Middleware may short-circuit

`HandlerMiddleware` should be allowed not to call `$next`.

Example:

```php
public function process(...): mixed
{
    if ($this->alreadyProcessed($message)) {
        return $this->cachedResult;
    }

    return $next(
        $message,
        $envelope,
        $context,
    );
}
```

This enables real middleware use cases such as:

* idempotency;
* authorization;
* cache-like deduplication;
* circuit guards.

If middleware short-circuits successfully, Consumer treats the handling operation as successful.

---

## 12. Middleware may transform results

This should also work naturally:

```php
$result = $next(...);

return $this->transform($result);
```

Whatever reaches the outer pipeline becomes the final handler result.

For `SyncTransport`, that final value becomes:

```php
new HandledStamp($result)
```

just as the handler result does today.

---

## 13. Optimize middleware construction

Persistent workers make this a hot path.

Normalize middleware **once**, not for every delivery.

Constructor should:

* materialize iterable once;
* validate every element implements `HandlerMiddleware`;
* preserve declaration order;
* avoid further structural validation per message.

Do not perform service resolution inside `HandlerInvoker`.

The caller/framework builds middleware objects.

---

## 14. Optimize zero-middleware path

Very important.

When middleware is empty:

```text
HandlerInvoker::invoke()
→ HandlerMap::for()
→ handler
```

Do not build a closure onion unnecessarily.

Something like:

```php
if ($this->middleware === []) {
    return $this->invokeHandler(...);
}
```

is worthwhile.

The majority of simple Omnibus users should pay almost nothing for the new feature.

---

## 15. Avoid per-delivery reflection

Do not use reflection on every message.

If any callable normalization eventually requires reflection:

* resolve once;
* cache by callable/handler identity where practical.

But for 2.3, preserving the existing sync-vs-async terminal invocation semantics avoids needing reflection entirely.

---

# 16. Do not add `Job`

Do **not** add:

```php
interface Job {}
```

to Omnibus.

`MessageBus` already dispatches arbitrary objects:

```php
dispatch(object $message)
```

That is the better low-level API.

Foundation can later decide what a framework Job means.

---

# 17. Do not add `Message`, `Command`, or `Event` markers

Avoid:

```text
Message
Command
Job
Event
```

marker interfaces.

Omnibus already supports arbitrary object types and type-based routing.

No taxonomy is needed merely to support Foundation.

---

# 18. Do not add `HandlerInterface`

Keep handlers as callables.

Current `HandlerMap` intentionally accepts callable mappings.

Continue supporting:

```php
final class GenerateReportHandler
{
    public function __invoke(
        GenerateReport $message,
    ): void {
    }
}
```

Do not force application classes through another interface.

---

# 19. Do not apply middleware to ordinary PSR events

`EventDispatcher` has a separate PSR-compatible listener lifecycle.

Do not make ordinary:

```php
$events->dispatch($event);
```

run through `HandlerMiddleware`.

Boundary:

```text
normal event listener
→ EventDispatcher lifecycle

queued event/message
→ MessageBus / Consumer
→ HandlerInvoker
→ HandlerMiddleware
```

That separation is correct.

---

# 20. Do not merge `ExecutionScope` and middleware

These solve different problems.

### `ExecutionScope`

Controls the execution lifetime/context.

### `HandlerMiddleware`

Intercepts handling behavior.

Keep both.

The structure should remain:

```text
ExecutionScope::run()
    ↓
HandlerInvoker::invoke()
    ↓
middleware
    ↓
handler
```

---

# 21. Do not add DI/container support to Omnibus

No:

```text
MiddlewareResolver
ContainerMiddlewareResolver
ServiceLocator
ContainerInterface dependency
```

inside core Omnibus.

Construct it normally:

```php
new HandlerInvoker(
    $handlers,
    [
        $tracing,
        $idempotency,
    ],
)
```

Foundation can later resolve middleware using InterMix.

---

# 22. Update construction/composition points

Every place currently constructing either:

```text
Consumer
SyncTransport
```

with a `HandlerMap` must be reviewed.

The preferred composition should become:

```php
$invoker = new HandlerInvoker(
    $handlerMap,
    $middleware,
);
```

and then share that invoker where appropriate:

```php
new SyncTransport($invoker);

new Consumer(
    receiver: $receiver,
    invoker: $invoker,
    ...
);
```

This ensures the same middleware configuration applies to synchronous and asynchronous handling when desired.

Do not duplicate separate middleware arrays in Consumer and SyncTransport.

---

# 23. Constructor changes

Recommended:

### Consumer

From roughly:

```php
__construct(
    Receiver $receiver,
    HandlerMap $handlers,
    RetryStrategy $retry,
    FailureStore $failures,
    ClockInterface $clock,
    ExecutionScope $scope = ...
)
```

to:

```php
__construct(
    Receiver $receiver,
    HandlerInvoker $handlers,
    RetryStrategy $retry,
    FailureStore $failures,
    ClockInterface $clock,
    ExecutionScope $scope = ...
)
```

I would name the property:

```php
private HandlerInvoker $invoker
```

for clarity.

### SyncTransport

From:

```php
__construct(
    HandlerMap $handlers,
)
```

to:

```php
__construct(
    HandlerInvoker $invoker,
)
```

Because this changes public constructors, document it prominently in the 2.3 upgrade notes.

If you want strict semver BC for 2.x, alternatively temporarily accept:

```php
HandlerMap|HandlerInvoker
```

and internally wrap `HandlerMap`.

However, given your library sprint has generally favored clean design over carrying unnecessary compatibility layers, I would prefer the clean constructor change if you're comfortable treating 2.3 as the integration baseline.

---

# 24. Prefer one middleware list per handler execution stack

Avoid introducing immediately:

```text
global middleware
consumer middleware
transport middleware
queue middleware
handler middleware
message middleware
```

Start with exactly one ordered:

```text
HandlerMiddleware[]
```

collection.

Later filtering can be implemented by middleware itself:

```php
if (!$message instanceof SomeMessage) {
    return $next(...);
}
```

This prevents configuration complexity.

---

# 25. No queue-specific middleware registry yet

Do not add:

```text
middlewareByQueue
middlewareByMessage
middlewareByTransport
```

in Omnibus 2.3.

Those concerns can be represented by ordinary middleware conditions.

If profiling later shows conditional checks are expensive at extreme scale, a compiled registry can be considered separately.

---

# 26. Context stays immutable

`HandlerContext` should be `final readonly`.

Middleware must not mutate execution metadata.

No:

```php
$context->queue = 'another';
```

Routing already happened before handler execution.

---

# 27. Context should not duplicate Envelope stamps

Do not stuff into `HandlerContext` things already available through `Envelope`:

* message ID;
* delay;
* route stamps;
* arbitrary delivery stamps.

Context exists only for information not naturally represented by the envelope execution contract:

```text
queue
attempt
asynchronous
```

Keep it minimal.

---

# 28. Do not modify `MessageBus`

No change needed to `MessageBus`.

Its role remains:

```text
object
→ Envelope
→ MessageIdStamp
→ RouteMap
→ RouteStamp
→ DelayStamp
→ Sender
```

Handler middleware is an execution concern, not dispatch routing.

---

# 29. Do not modify retry strategy APIs

No changes required to:

```text
RetryStrategy
FailureStore
FailureManager
```

Middleware exceptions naturally enter the current Consumer error path.

This is a major benefit of placing HandlerInvoker inside the existing Consumer `try`.

---

# 30. Do not modify transport interfaces

No need to change:

```text
Sender
Receiver
```

The new middleware pipeline is internal handler-execution composition.

Transport implementations shouldn't suddenly know about middleware.

Only `SyncTransport` changes because it itself performs handler execution.

---

# 31. Handler middleware testing fixture

Add a reusable fixture, for example:

```text
tests/Fixtures/RecordingHandlerMiddleware.php
```

It can record:

```text
before:a
before:b
handler
after:b
after:a
```

This makes ordering and short-circuit tests much cleaner.

---

# 32. `HandlerContext` unit tests

Add tests covering:

* valid sync context;
* valid async context;
* queue preserved;
* attempt preserved;
* empty queue rejected;
* zero attempt rejected;
* negative attempt rejected;
* readonly behavior structurally guaranteed.

---

# 33. `HandlerInvoker` unit tests

Cover at minimum:

* no middleware;
* one middleware;
* multiple middleware;
* correct nesting;
* deterministic ordering;
* handler called exactly once;
* return value preserved;
* middleware transforms result;
* middleware short-circuits;
* handler exception propagated unchanged;
* middleware exception propagated unchanged;
* envelope object passed unchanged;
* context object passed unchanged.

---

# 34. `Consumer` regression tests

Add/update tests ensuring:

* handler still receives expected arguments;
* middleware runs within Consumer;
* context has actual queue;
* context has reservation attempt;
* `asynchronous === true`;
* successful middleware + handler causes acknowledge;
* retriable middleware exception causes release;
* exhausted middleware exception causes reject + FailureStore record;
* short-circuited successful middleware causes acknowledge;
* ExecutionScope wraps complete middleware chain.

---

# 35. `SyncTransport` regression tests

Verify:

* delay rejection unchanged;
* middleware executes;
* queue context correct;
* attempt = 1;
* asynchronous = false;
* handler result becomes `HandledStamp`;
* short-circuit result becomes `HandledStamp`;
* middleware exception propagates;
* existing terminal handler argument behavior remains compatible.

---

# 36. Worker regression tests

Worker itself should not require architectural changes.

But verify:

```text
Worker
→ Consumer
→ HandlerInvoker
```

continues to respect:

* requestStop();
* limits;
* failure handling;
* memory limits;
* idle logic;
* retry behavior.

Persistent worker runs must not accumulate middleware state unexpectedly.

---

# 37. WorkerPool regression tests

Also verify child workers receive the correctly constructed Consumer/HandlerInvoker stack.

No middleware objects should accidentally be shared across fork boundaries in unsafe ways.

Existing worker-pool lifecycle remains unchanged.

---

# 38. Add middleware benchmark

The repo already has a benchmark directory.

Add something like:

```text
benchmarks/handler_middleware.php
```

Benchmark:

```text
HandlerMap direct baseline

HandlerInvoker:
0 middleware
1 middleware
3 middleware
5 middleware
10 middleware
```

Measure:

* operations/sec;
* ns/op;
* peak memory;
* repeated execution memory stability.

---

# 39. Benchmark both invocation paths

Benchmark separately:

```text
HandlerInvoker direct
SyncTransport
Consumer
```

The direct invoker benchmark isolates middleware overhead.

Sync/Consumer benchmarks reveal real execution-path impact.

---

# 40. Performance acceptance

Target:

### Zero middleware

Very small regression versus existing direct HandlerMap path.

### N middleware

Approximately linear overhead.

### Persistent execution

No memory increase proportional to processed message count.

### No reflection

No reflection in ordinary per-message hot path.

---

# 41. Documentation update

Update README/docs with a focused **Handler Middleware** section.

Document:

```php
final readonly class TraceMiddleware implements HandlerMiddleware
{
    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed {
        // before

        try {
            return $next(
                $message,
                $envelope,
                $context,
            );
        } finally {
            // after
        }
    }
}
```

Explain explicitly that middleware:

* surrounds handler execution;
* runs for sync and consumer handling;
* can short-circuit;
* can transform results;
* participates in Consumer retry/failure semantics.

---

# 42. Document what middleware does NOT do

Clarify that it does not intercept:

```text
MessageBus routing
serialization
transport send
transport receive
ordinary PSR event listeners
worker process lifecycle
```

This avoids misuse later.

---

# 43. Upgrade notes

Document constructor/composition changes:

```text
HandlerMap
    ↓
HandlerInvoker
```

If constructor BC is intentionally broken:

```php
new SyncTransport(
    new HandlerInvoker($handlers),
);
```

and:

```php
new Consumer(
    $receiver,
    new HandlerInvoker($handlers),
    ...
);
```

should be shown.

---

# 44. Public API after implementation

New public classes:

```text
Infocyph\Omnibus\Handler\HandlerContext
Infocyph\Omnibus\Handler\HandlerMiddleware
Infocyph\Omnibus\Handler\HandlerInvoker
```

Existing public concepts remain:

```text
HandlerMap
MessageBus
Envelope
Consumer
Worker
WorkerPool
SyncTransport
Sender
Receiver
RetryStrategy
FailureStore
ExecutionScope
EventDispatcher
```

No additional framework abstractions.

---

# 45. Exact expected source-file changes

### Add

```text
src/Handler/HandlerContext.php
src/Handler/HandlerMiddleware.php
src/Handler/HandlerInvoker.php
```

### Update

```text
src/Consumer/Consumer.php
src/Transport/SyncTransport.php
```

### Review/update every composition point constructing

```text
Consumer
SyncTransport
HandlerMap
```

so `HandlerInvoker` is correctly shared/injected.

Depending on the current test/application composition, this will include test factories/fixtures and potentially examples/docs rather than additional production classes.

---

# 46. Exact test work

At minimum:

```text
tests/Unit/Handler/HandlerContextTest.php
tests/Unit/Handler/HandlerInvokerTest.php
```

plus existing Consumer and SyncTransport tests updated/expanded wherever they currently live.

Also add fixture middleware if useful:

```text
tests/Fixtures/RecordingHandlerMiddleware.php
```

And regression coverage through workers/worker pools.

---

# 47. Do not add these

Explicitly reject for this Omnibus change:

```text
Job
JobMiddleware
Message marker
Command marker
Event marker
Handler interface
Middleware registry by message
Middleware registry by queue
Framework config
Container integration
Foundation dependency
ReqShield dependency
TalkingBytes dependency
```

This is crucial to keeping Omnibus independent.

---

# 48. Release target

Recommended:

```text
infocyph/omnibus 2.3.0
```

because this adds a meaningful new public execution capability.

After release, Foundation can consume:

```json
"infocyph/omnibus": "^2.3"
```

instead of `^2.2`.

---

## Final implementation checklist

The actual engineering task boils down to:

1. Add immutable `HandlerContext`.
2. Add generic `HandlerMiddleware`.
3. Add `HandlerInvoker`.
4. Keep `HandlerMap` purely as resolver.
5. Preserve existing terminal callable semantics.
6. Give middleware one normalized context.
7. Route Consumer through HandlerInvoker.
8. Route SyncTransport through HandlerInvoker.
9. Keep HandlerInvoker inside ExecutionScope.
10. Let middleware exceptions use existing retry/failure logic.
11. Support short-circuiting.
12. Support result transformation.
13. Preserve ordered onion execution.
14. Optimize zero-middleware fast path.
15. Normalize/validate middleware once.
16. Avoid reflection in the hot path.
17. Update all Consumer/SyncTransport composition points.
18. Do not modify MessageBus routing.
19. Do not modify Sender/Receiver contracts.
20. Do not modify RetryStrategy/FailureStore architecture.
21. Do not route ordinary PSR events through middleware.
22. Keep arbitrary-object messages.
23. Keep callable handlers.
24. Add HandlerContext tests.
25. Add HandlerInvoker tests.
26. Expand Consumer retry/failure/middleware tests.
27. Expand SyncTransport tests.
28. Add Worker/WorkerPool regressions.
29. Add handler middleware benchmark.
30. Verify persistent-worker memory stability.
31. Update README/docs.
32. Add 2.3 upgrade notes.
33. Release as **Omnibus 2.3.0**.

That is the complete Omnibus change scope I would use as the implementation draft.

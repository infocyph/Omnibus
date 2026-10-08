<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Dispatch\AfterResponseDispatcher;
use Infocyph\Omnibus\Dispatch\AfterResponseRuntime;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\FailureRetryClaimUnavailable;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryLockProvider;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Workflow\InMemoryWorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowCoordinator;
use Infocyph\Omnibus\Workflow\WorkflowDispatchFailed;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function omnibusRecoveryRuntime(): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
        ),
        'omnibus-recovery-test',
        workerSlot: 0,
        generation: 1,
    );
}

test('late host cancellation settles completed side effects before stopping prefetched work', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('first')), 'work');
    $transport->send(new Envelope(new TestCommand('second')), 'work');
    $binding = new RunwireBinding();
    $runtime = omnibusRecoveryRuntime();
    $request = RequestContext::create($runtime, requestId: 'late-cancel');
    $handled = [];

    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function (TestCommand $message) use (&$handled, $request): void {
                $handled[] = $message->value;
                if ($message->value === 'first') {
                    $request->cancel(CancellationReason::HOST_CANCELLED);
                }
            },
        ])),
        new ExponentialRetryStrategy(maximumAttempts: 10),
        new InMemoryFailureStore($clock),
        $clock,
        runwire: $binding,
    );

    expect(fn() => $consumer->withRunwire(
        $runtime,
        fn() => $consumer->run('work', limit: 2, visibilitySeconds: 1),
        $request,
    ))->toThrow(CancelledException::class);

    expect($handled)->toBe(['first'])
        ->and($transport->size('work'))->toBe(0);

    $clock->advance('+2 seconds');
    expect($transport->size('work'))->toBe(1);
});

test('host cancellation thrown during a handler is never converted into an automatic retry', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('cancel-me')), 'work');
    $binding = new RunwireBinding();
    $runtime = omnibusRecoveryRuntime();
    $request = RequestContext::create($runtime, requestId: 'handler-cancel');
    $failures = new InMemoryFailureStore($clock);

    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function () use ($request): void {
                $request->cancel(CancellationReason::HOST_CANCELLED);
                $request->cancellation->throwIfCancelled();
            },
        ])),
        new ExponentialRetryStrategy(maximumAttempts: 10),
        $failures,
        $clock,
        runwire: $binding,
    );

    expect(fn() => $consumer->withRunwire(
        $runtime,
        fn() => $consumer->run('work', visibilitySeconds: 1),
        $request,
    ))->toThrow(CancelledException::class)
        ->and($failures->all())->toBe([]);

    $clock->advance('+2 seconds');
    expect($transport->size('work'))->toBe(1);
});

test('ambiguous failed-message retry keeps its claim until expiry', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryFailureStore($clock);
    $store->add(FailedMessage::decoded(
        'failed-message',
        'work',
        new Envelope(new TestCommand('retry')),
        1,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));
    $manager = new FailureManager($store);
    $sender = new class implements Sender {
        public int $attempts = 0;

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->attempts++;
            if ($this->attempts === 1) {
                throw new RuntimeException('broker outcome unknown');
            }

            return $envelope;
        }
    };

    expect(fn() => $manager->retry('failed-message', $sender, claimLeaseSeconds: 2))
        ->toThrow(RuntimeException::class, 'broker outcome unknown')
        ->and(fn() => $manager->retry('failed-message', $sender, claimLeaseSeconds: 2))
        ->toThrow(FailureRetryClaimUnavailable::class);

    $clock->advance('+3 seconds');
    $manager->retry('failed-message', $sender, claimLeaseSeconds: 2);

    expect($sender->attempts)->toBe(2)
        ->and($store->find('failed-message'))->toBeNull();
});

test('ambiguous workflow dispatch retains the attempted claim but releases unattempted claims', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryWorkflowStore($clock);
    $sender = new class implements Sender {
        public int $attempts = 0;

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->attempts++;
            if ($this->attempts === 1) {
                throw new RuntimeException('workflow broker outcome unknown');
            }

            return $envelope;
        }
    };
    $coordinator = new WorkflowCoordinator($store, $sender, dispatchLeaseSeconds: 2);
    $workflowId = null;

    try {
        $coordinator->batch([
            new TestCommand('ambiguous'),
            new TestCommand('unattempted'),
        ], 'work');
    } catch (WorkflowDispatchFailed $failure) {
        $workflowId = $failure->workflowId;
    }

    expect($workflowId)->toBeString()
        ->and($coordinator->dispatchPending($workflowId, 10))->toBe(1)
        ->and($sender->attempts)->toBe(2);

    $clock->advance('+3 seconds');
    expect($coordinator->dispatchPending($workflowId, 10))->toBe(1)
        ->and($sender->attempts)->toBe(3);
});


test('workflow confirmation failures release only unattempted dispatch claims immediately', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryWorkflowStore($clock);
    $sender = new class($store) implements Sender {
        public int $attempts = 0;

        public function __construct(private readonly InMemoryWorkflowStore $store) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->attempts++;
            if ($this->attempts === 1) {
                $identity = \Infocyph\Omnibus\Workflow\WorkflowItem::identity($envelope);
                if ($identity === null) {
                    throw new LogicException('Expected a stamped workflow item.');
                }
                $this->store->fail($identity['workflow_id'], $identity['item_id'], $identity['index']);
            }

            return $envelope;
        }
    };
    $coordinator = new WorkflowCoordinator($store, $sender, dispatchLeaseSeconds: 60);
    $workflowId = null;

    try {
        $coordinator->batch([
            new TestCommand('confirmation-fails'),
            new TestCommand('unattempted'),
        ], 'work');
    } catch (WorkflowDispatchFailed $failure) {
        $workflowId = $failure->workflowId;
    }

    expect($workflowId)->toBeString()
        ->and($sender->attempts)->toBe(1)
        ->and($coordinator->dispatchPending($workflowId, 10))->toBe(1)
        ->and($sender->attempts)->toBe(2);
});

test('ambiguous unique send holds the detached lease until its TTL expires', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $locks = new InMemoryLockProvider($clock);
    $sender = new class implements Sender {
        public int $attempts = 0;

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->attempts++;
            if ($this->attempts === 1) {
                throw new RuntimeException('unique broker outcome unknown');
            }

            return $envelope;
        }
    };
    $unique = new UniqueSender(
        $sender,
        $locks,
        static fn(): string => 'order-42',
        leaseSeconds: 2,
    );
    $message = new Envelope(new TestCommand('unique'));

    expect(fn() => $unique->send($message, 'work'))
        ->toThrow(RuntimeException::class, 'unique broker outcome unknown')
        ->and(fn() => $unique->send($message, 'work'))
        ->toThrow(DuplicateMessage::class);

    $clock->advance('+3 seconds');
    $unique->send($message, 'work');

    expect($sender->attempts)->toBe(2);
});

test('stale failure retry tokens cannot settle a newer claim', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryFailureStore($clock);
    $store->add(FailedMessage::decoded(
        'stale-claim',
        'work',
        new Envelope(new TestCommand('retry')),
        1,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));

    $first = $store->claimRetry('stale-claim', 1);
    $clock->advance('+2 seconds');
    $second = $store->claimRetry('stale-claim', 30);

    expect($store->markRetrySent($first))->toBeFalse()
        ->and($store->releaseRetry($first))->toBeFalse()
        ->and($store->markRetrySent($second))->toBeTrue()
        ->and($store->removeRetried($second))->toBeTrue();
});


test('after-response work does not retain the originating Runwire request', function (): void {
    $binding = new RunwireBinding();
    $runtime = omnibusRecoveryRuntime();
    $request = RequestContext::create($runtime, requestId: 'origin-response');
    $callbacks = [];
    $deferred = new class($callbacks) implements AfterResponseRuntime {
        /** @var list<callable():void> */
        public array $callbacks;

        /** @param list<callable():void> $callbacks */
        public function __construct(array &$callbacks)
        {
            $this->callbacks = &$callbacks;
        }

        public function defer(callable $callback): void
        {
            $this->callbacks[] = $callback;
        }
    };
    $observedRequest = 'unset';
    $sender = new class($binding, $observedRequest) implements Sender {
        public function __construct(
            private readonly RunwireBinding $binding,
            private string &$observedRequest,
        ) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->observedRequest = $this->binding->request()?->requestId ?? 'none';

            return $envelope;
        }
    };
    $dispatcher = new AfterResponseDispatcher(
        new MessageBus(
            new RouteMap(default: new Route('recording')),
            new TransportRegistry(['recording' => $sender]),
            $binding,
        ),
        $deferred,
    );

    $binding->withRunwire(
        $runtime,
        function () use ($dispatcher): void {
            $dispatcher->dispatch(new TestCommand('after-response'));
        },
        $request,
    );
    $request->complete();

    expect($callbacks)->toHaveCount(1)
        ->and($binding->request())->toBeNull();

    $callbacks[0]();

    expect($observedRequest)->toBe('none')
        ->and($binding->request())->toBeNull();
});

test('in-memory failure pruning preserves active and sent retry claims', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryFailureStore($clock);
    foreach (['unclaimed', 'active', 'sent'] as $id) {
        $store->add(FailedMessage::decoded(
            $id,
            'work',
            new Envelope(new TestCommand($id)),
            1,
            $clock->now()->modify('-2 days'),
            RuntimeException::class,
            'failed',
        ));
    }
    $active = $store->claimRetry('active');
    $sent = $store->claimRetry('sent');
    expect($store->markRetrySent($sent))->toBeTrue()
        ->and($store->prune($clock->now()))->toBe(1)
        ->and($store->find('unclaimed'))->toBeNull()
        ->and($store->find('active'))->not->toBeNull()
        ->and($store->find('sent'))->not->toBeNull()
        ->and($store->releaseRetry($active))->toBeTrue()
        ->and($store->prune($clock->now()))->toBe(1)
        ->and($store->removeRetried($sent))->toBeTrue();
});

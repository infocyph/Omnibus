<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\CancellationStamp;
use Infocyph\Omnibus\Consumer\DeadlineExecutionScope;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Consumer\ExecutionTimedOut;
use Infocyph\Omnibus\Consumer\ExecutionTimedOutAfterExecution;
use Infocyph\Omnibus\Dispatch\AfterResponseDispatcher;
use Infocyph\Omnibus\Dispatch\AfterResponseRuntime;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitBreakerScope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitOpen;
use Infocyph\Omnibus\Integration\CacheLayer\FixedWindowRateLimitScope;
use Infocyph\Omnibus\Integration\CacheLayer\LeaseLost;
use Infocyph\Omnibus\Integration\CacheLayer\OverlapProtectionScope;
use Infocyph\Omnibus\Integration\CacheLayer\OverlapLeaseLostAfterExecution;
use Infocyph\Omnibus\Integration\CacheLayer\PolicyKey;
use Infocyph\Omnibus\Integration\CacheLayer\RateLimitExceeded;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Retry\NonRetryableFailure;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryCounterStore;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryLockProvider;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('unique lease survives retries and ends on settlement', function (): void {
    $locks = new InMemoryLockProvider();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new UniqueTransport(new InMemoryTransport($clock), $locks);
    $sender = new UniqueSender(
        $transport,
        $locks,
        static fn(Envelope $envelope): string => $envelope->message::class,
    );
    $sender->send(new Envelope(new TestCommand('one')), 'work');

    expect(fn() => $sender->send(new Envelope(new TestCommand('two')), 'work'))
        ->toThrow(Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage::class);

    $reservation = [...$transport->receive('work')][0];
    $unique = $reservation->envelope()->last(UniqueStamp::class);
    expect($unique)->toBeInstanceOf(UniqueStamp::class)
        ->and($unique?->key)->toMatch('/^omnibus\.[a-f0-9]{32}$/D');
    $transport->release($reservation, 5);
    expect($locks->lastRefreshedLease)->toBe(305.0);
    expect(fn() => $sender->send(new Envelope(new TestCommand('two')), 'work'))
        ->toThrow(Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage::class);

    $clock->advance('+5 seconds');
    $redelivery = [...$transport->receive('work')][0];
    $transport->acknowledge($redelivery);
    expect($sender->send(new Envelope(new TestCommand('three')), 'work'))
        ->toBeInstanceOf(Envelope::class);
});

test('policy counter keys remain inside CacheLayer bounds after suffixes', function (): void {
    $base = PolicyKey::storage('circuit', str_repeat('logical-key', 40));

    expect(strlen($base.'.failures'))->toBeLessThanOrEqual(64)
        ->and(strlen($base.'.'.PHP_INT_MAX))->toBeLessThanOrEqual(64)
        ->and($base)->toMatch('/^omnibus\.[a-f0-9]{32}$/D');
});

test('unique cleanup failure cannot undo durable queue settlement', function (): void {
    $locks = new InMemoryLockProvider();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $inner = new InMemoryTransport($clock);
    $reported = 0;
    $transport = new UniqueTransport(
        $inner,
        $locks,
        static function () use (&$reported): void {
            $reported++;
        },
    );
    $sender = new UniqueSender(
        $transport,
        $locks,
        static fn(Envelope $envelope): string => $envelope->message::class,
    );
    $sender->send(new Envelope(new TestCommand('one')), 'work');
    $reservation = [...$transport->receive('work')][0];
    $locks->releaseFails = true;

    $transport->acknowledge($reservation);

    expect($reported)->toBe(1)
        ->and($inner->size('work'))->toBe(0);
});

test('overlap protection reports lease loss and always releases', function (): void {
    $locks = new InMemoryLockProvider();
    $locks->refreshable = false;
    $scope = new OverlapProtectionScope(
        new DirectExecutionScope(),
        $locks,
        static fn(): string => 'account:1',
    );

    expect(fn() => $scope->run(new Envelope(new TestCommand('one')), static fn(): null => null))
        ->toThrow(LeaseLost::class);

    $locks->refreshable = true;
    expect($scope->run(new Envelope(new TestCommand('two')), static fn(): string => 'ok'))
        ->toBe('ok');
});

test('rate limit and circuit breaker use CacheLayer atomic state', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $counters = new InMemoryCounterStore();
    $rate = new FixedWindowRateLimitScope(
        new DirectExecutionScope(),
        $counters,
        $clock,
        static fn(): string => 'tenant:1',
        2,
        60,
    );
    $envelope = new Envelope(new TestCommand('work'));
    $rate->run($envelope, static fn(): null => null);
    $rate->run($envelope, static fn(): null => null);
    expect(fn() => $rate->run($envelope, static fn(): null => null))
        ->toThrow(RateLimitExceeded::class);

    $circuit = new CircuitBreakerScope(
        new DirectExecutionScope(),
        $counters,
        new InMemoryLockProvider(),
        $clock,
        static fn(): string => 'provider:1',
        failureThreshold: 2,
        recoverySeconds: 5,
    );
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $circuit->run($envelope, static fn() => throw new RuntimeException('down'));
        } catch (RuntimeException) {
        }
    }
    expect(fn() => $circuit->run($envelope, static fn(): null => null))
        ->toThrow(CircuitOpen::class);

    $clock->advance('+6 seconds');
    $probes = 0;
    expect($circuit->run($envelope, function () use ($circuit, $envelope, &$probes): string {
        $probes++;
        expect(fn() => $circuit->run($envelope, static fn(): null => null))
            ->toThrow(CircuitOpen::class);

        return 'recovered';
    }))->toBe('recovered')
        ->and($probes)->toBe(1);
});

test('circuit recovery admits one probe and reopens or recovers with expiring backend state', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $counters = new InMemoryCounterStore($clock);
    $locks = new InMemoryLockProvider($clock);
    $scope = new CircuitBreakerScope(
        new DirectExecutionScope(),
        $counters,
        $locks,
        $clock,
        static fn(): string => 'provider:expiring',
        failureThreshold: 1,
        recoverySeconds: 5,
        probeLeaseSeconds: 10,
    );
    $envelope = new Envelope(new TestCommand('probe'));
    try {
        $scope->run($envelope, static fn() => throw new RuntimeException('down'));
    } catch (RuntimeException) {
    }
    $clock->advance('+6 seconds');
    $blocked = 0;
    expect($scope->run($envelope, function () use ($scope, $envelope, &$blocked): string {
        for ($caller = 0; $caller < 10; $caller++) {
            try {
                $scope->run($envelope, static fn(): null => null);
            } catch (CircuitOpen) {
                $blocked++;
            }
        }

        return 'healthy';
    }))->toBe('healthy')
        ->and($blocked)->toBe(10)
        ->and($scope->run($envelope, static fn(): string => 'closed'))->toBe('closed');

    try {
        $scope->run($envelope, static fn() => throw new RuntimeException('down again'));
    } catch (RuntimeException) {
    }
    $clock->advance('+6 seconds');
    expect(fn() => $scope->run($envelope, static fn() => throw new DomainException('bad probe')))
        ->toThrow(DomainException::class);
    expect(fn() => $scope->run($envelope, static fn(): null => null))
        ->toThrow(CircuitOpen::class);

    $clock->advance('+6 seconds');
    $probeKey = PolicyKey::storage('circuit', 'provider:expiring').'.probe';
    expect($locks->acquire($probeKey, 0, 10))->not->toBeNull()
        ->and(fn() => $scope->run($envelope, static fn(): null => null))
        ->toThrow(CircuitOpen::class);
    $clock->advance('+11 seconds');
    expect($scope->run($envelope, static fn(): string => 'after-crash'))->toBe('after-crash');
});

test('circuit bookkeeping cannot replace a completed handler outcome', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $envelope = new Envelope(new TestCommand('bookkeeping'));
    $successLocks = new InMemoryLockProvider($clock);
    $successLocks->failAcquireAt = 2;
    $success = new CircuitBreakerScope(
        new DirectExecutionScope(),
        new InMemoryCounterStore($clock),
        $successLocks,
        $clock,
        static fn(): string => 'success',
    );
    expect($success->run($envelope, static fn(): string => 'business-result'))->toBe('business-result');

    $failureLocks = new InMemoryLockProvider($clock);
    $failureLocks->failAcquireAt = 2;
    $failure = new CircuitBreakerScope(
        new DirectExecutionScope(),
        new InMemoryCounterStore($clock),
        $failureLocks,
        $clock,
        static fn(): string => 'failure',
    );
    expect(fn() => $failure->run($envelope, static fn() => throw new DomainException('handler-primary')))
        ->toThrow(DomainException::class, 'handler-primary');
});

test('retry delay math remains finite and bounded for every supported extreme', function (): void {
    $policies = [
        new ExponentialRetryStrategy(initialDelaySeconds: 1, multiplier: PHP_FLOAT_MAX, maximumDelaySeconds: 60),
        new ExponentialRetryStrategy(initialDelaySeconds: 0, multiplier: PHP_FLOAT_MAX, maximumDelaySeconds: 60),
        new ExponentialRetryStrategy(initialDelaySeconds: 10, maximumDelaySeconds: 0),
        new ExponentialRetryStrategy(initialDelaySeconds: 1, maximumDelaySeconds: 60, jitterRatio: 1),
        new ExponentialRetryStrategy(
            initialDelaySeconds: PHP_FLOAT_MIN,
            multiplier: 1.0001,
            maximumDelaySeconds: PHP_FLOAT_MAX,
        ),
    ];

    foreach ($policies as $policy) {
        foreach ([1, PHP_INT_MAX] as $attempt) {
            $delay = $policy->delaySeconds($attempt);
            expect(is_finite($delay))->toBeTrue()
                ->and($delay)->toBeGreaterThanOrEqual(0)
                ->and($delay)->toBeLessThanOrEqual($policy->maximumDelaySeconds);
        }
    }

    expect(fn() => $policies[0]->delaySeconds(0))->toThrow(InvalidArgumentException::class);
});

test('post-handler timeout and lease loss are explicitly non-retryable', function (): void {
    expect(new ExecutionTimedOutAfterExecution(new DateTimeImmutable('2026-01-01T00:00:00+00:00')))
        ->toBeInstanceOf(NonRetryableFailure::class)
        ->and(new OverlapLeaseLostAfterExecution('lost'))->toBeInstanceOf(NonRetryableFailure::class)
        ->and(new ExecutionTimedOut(new DateTimeImmutable('2026-01-01T00:00:00+00:00')))
        ->not->toBeInstanceOf(NonRetryableFailure::class)
        ->and(new LeaseLost('before'))->not->toBeInstanceOf(NonRetryableFailure::class);
});

test('deadline scope exposes cooperative cancellation without process signals', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $scope = new DeadlineExecutionScope(new DirectExecutionScope(), $clock, 1);

    expect(fn() => $scope->run(
        new Envelope(new TestCommand('slow')),
        static function (TestCommand $message, Envelope $envelope) use ($clock): void {
            expect($message->value)->toBe('slow')
                ->and($envelope->last(CancellationStamp::class))
                ->toBeInstanceOf(CancellationStamp::class);
            $clock->advance('+2 seconds');
        },
    ))->toThrow(ExecutionTimedOut::class);
});

test('after-response dispatch delegates timing to the host runtime', function (): void {
    $callbacks = [];
    $runtime = new class($callbacks) implements AfterResponseRuntime {
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
    $sender = new RecordingSender();
    $dispatcher = new AfterResponseDispatcher(
        new MessageBus(new RouteMap(default: new Infocyph\Omnibus\Routing\Route('recording')), new TransportRegistry([
            'recording' => $sender,
        ])),
        $runtime,
    );

    $dispatcher->dispatch(new TestCommand('later'));
    expect($sender->count())->toBe(0);
    $runtime->callbacks[0]();
    expect($sender->count())->toBe(1);
});

<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\CancellationStamp;
use Infocyph\Omnibus\Consumer\DeadlineExecutionScope;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Consumer\ExecutionTimedOut;
use Infocyph\Omnibus\Dispatch\AfterResponseDispatcher;
use Infocyph\Omnibus\Dispatch\AfterResponseRuntime;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitBreakerScope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitOpen;
use Infocyph\Omnibus\Integration\CacheLayer\FixedWindowRateLimitScope;
use Infocyph\Omnibus\Integration\CacheLayer\LeaseLost;
use Infocyph\Omnibus\Integration\CacheLayer\OverlapProtectionScope;
use Infocyph\Omnibus\Integration\CacheLayer\RateLimitExceeded;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\MessageBus;
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
        ->and($unique?->key)->toMatch('/^omnibus\.unique\.[a-f0-9]{64}$/D');
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
    expect($circuit->run($envelope, static fn(): string => 'recovered'))->toBe('recovered');
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

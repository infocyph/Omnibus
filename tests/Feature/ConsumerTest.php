<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Receiver;
use Infocyph\Omnibus\Transport\Reservation;

test('consumer acknowledges successful messages', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = [];
    $transport->send(
        new Envelope(new TestCommand('one'), [new MessageIdStamp('message-1')]),
        'default',
    );
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                $handled[] = $message->value;
            },
        ])),
        new ExponentialRetryStrategy(initialDelaySeconds: 0),
        new InMemoryFailureStore(),
        $clock,
    );

    $result = $consumer->run();

    expect($result->succeeded)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($transport->size('default'))->toBe(0)
        ->and($handled)->toBe(['one']);
});

test('consumer retries transient failures and records terminal failures', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $failures = new InMemoryFailureStore();
    $transport->send(
        new Envelope(new TestCommand('fail'), [new MessageIdStamp('message-2')]),
        'default',
    );
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static fn() => throw new RuntimeException('broken'),
        ])),
        new ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: 0),
        $failures,
        $clock,
    );

    $first = $consumer->run();
    $second = $consumer->run();

    expect($first->released)->toBe(1)
        ->and($second->failed)->toBe(1)
        ->and($transport->size('default'))->toBe(0)
        ->and($failures->find('message-2')?->reason)->toBe('broken')
        ->and($failures->find('message-2')?->attempt)->toBe(2);
});

test('expired reservations become available for redelivery', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('restore')), 'default');

    $reservations = [...$transport->receive('default', visibilitySeconds: 2)];
    expect($reservations)->toHaveCount(1)
        ->and($transport->size('default'))->toBe(0);

    $clock->advance('+3 seconds');

    $redelivered = [...$transport->receive('default')][0];
    expect($redelivered->attempt)->toBe(2)
        ->and($redelivered->envelope()->all(AttemptStamp::class))->toHaveCount(1)
        ->and($redelivered->envelope()->last(AttemptStamp::class)?->attempt)->toBe(2);
});

test('queue size reports visible depth rather than delayed messages', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(
        new Envelope(new TestCommand('later'), [new DelayStamp(2)]),
        'default',
    );

    expect($transport->size('default'))->toBe(0);
    $clock->advance('+2 seconds');
    expect($transport->size('default'))->toBe(1);
});

test('consumer rejects poison payloads once without invoking retry or handlers', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $receiver = new class implements Receiver {
        public int $rejected = 0;

        public function acknowledge(Reservation $reservation): void
        {
            throw new LogicException('Poison message '.$reservation->receipt.' cannot be acknowledged.');
        }

        public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
        {
            if ($limit < 1 || $visibilitySeconds <= 0.0) {
                return [];
            }

            return [
                Reservation::undecodable(
                    'poison-1',
                    $queue,
                    new DecodeFailure('{broken', JsonException::class, 'Syntax error'),
                    1,
                    'poison-1',
                ),
            ];
        }

        public function reject(Reservation $reservation): void
        {
            $this->rejected += $reservation->attempt;
        }

        public function release(Reservation $reservation, float $delaySeconds = 0.0): void
        {
            throw new LogicException(sprintf(
                'Poison message %s cannot be released after %.2f seconds.',
                $reservation->receipt,
                $delaySeconds,
            ));
        }

        public function size(string $queue): int
        {
            return $queue === 'default' ? 0 : 1;
        }
    };
    $failures = new InMemoryFailureStore();
    $consumer = new Consumer(
        $receiver,
        new HandlerInvoker(new HandlerMap([])),
        new ExponentialRetryStrategy(),
        $failures,
        $clock,
    );

    $result = $consumer->run();
    $failure = $failures->find('poison-1');

    expect($result->failed)->toBe(1)
        ->and($result->released)->toBe(0)
        ->and($receiver->rejected)->toBe(1)
        ->and($failure?->envelope)->toBeNull()
        ->and($failure?->payload)->toBe('{broken')
        ->and($failure?->failureClass)->toBe(JsonException::class);
});

test('consumer retries middleware failures with reservation context and then acknowledges', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(
        new Envelope(new TestCommand('middleware'), [new MessageIdStamp('middleware-retry')]),
        'work',
    );
    $contexts = [];
    $handled = 0;
    $middleware = new class($contexts) implements HandlerMiddleware {
        /** @param list<HandlerContext> $contexts */
        public function __construct(public array &$contexts) {}

        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            $this->contexts[] = $context;
            if ($context->attempt === 1) {
                throw new RuntimeException('retry middleware');
            }

            return $next($message, $envelope, $context);
        }
    };
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function () use (&$handled): void {
                $handled++;
            },
        ]), [$middleware]),
        new ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: 0),
        new InMemoryFailureStore(),
        $clock,
    );

    $first = $consumer->run('work');
    $second = $consumer->run('work');

    expect($first->released)->toBe(1)
        ->and($second->succeeded)->toBe(1)
        ->and($handled)->toBe(1)
        ->and($contexts)->toHaveCount(2)
        ->and($contexts[0]->queue)->toBe('work')
        ->and($contexts[0]->attempt)->toBe(1)
        ->and($contexts[1]->attempt)->toBe(2)
        ->and($contexts[1]->asynchronous)->toBeTrue()
        ->and($transport->size('work'))->toBe(0);
});

test('consumer records exhausted middleware failures and acknowledges successful short circuits', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $failures = new InMemoryFailureStore();
    $transport->send(
        new Envelope(new TestCommand('failure'), [new MessageIdStamp('middleware-failure')]),
        'work',
    );
    $throwing = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Consumer middleware arguments are inconsistent.');
            }

            throw new RuntimeException('terminal middleware');
        }
    };
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([]), [$throwing]),
        new ExponentialRetryStrategy(maximumAttempts: 1),
        $failures,
        $clock,
    );

    expect($consumer->run('work')->failed)->toBe(1)
        ->and($failures->find('middleware-failure')?->reason)->toBe('terminal middleware');

    $handled = 0;
    $transport->send(new Envelope(new TestCommand('cached')), 'work');
    $shortCircuit = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Consumer middleware arguments are inconsistent.');
            }

            return 'cached';
        }
    };
    $shortCircuitConsumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function () use (&$handled): void {
                $handled++;
            },
        ]), [$shortCircuit]),
        new ExponentialRetryStrategy(),
        $failures,
        $clock,
    );

    expect($shortCircuitConsumer->run('work')->succeeded)->toBe(1)
        ->and($handled)->toBe(0)
        ->and($transport->size('work'))->toBe(0);
});

test('execution scope surrounds the complete middleware pipeline', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('scoped')), 'work');
    $events = [];
    $scope = new class($events) implements ExecutionScope {
        /** @param list<string> $events */
        public function __construct(public array &$events) {}

        public function run(Envelope $envelope, callable $handler): mixed
        {
            $this->events[] = 'scope:before';

            try {
                return $handler($envelope->message, $envelope);
            } finally {
                $this->events[] = 'scope:after';
            }
        }
    };
    $middleware = new class($events) implements HandlerMiddleware {
        /** @param list<string> $events */
        public function __construct(public array &$events) {}

        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            $this->events[] = 'middleware:before';
            $result = $next($message, $envelope, $context);
            $this->events[] = 'middleware:after';

            return $result;
        }
    };
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function () use (&$events): void {
                $events[] = 'handler';
            },
        ]), [$middleware]),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
        $scope,
    );

    $consumer->run('work');

    expect($events)->toBe([
        'scope:before',
        'middleware:before',
        'handler',
        'middleware:after',
        'scope:after',
    ]);
});

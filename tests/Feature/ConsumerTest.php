<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
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
        new HandlerMap([
            TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                $handled[] = $message->value;
            },
        ]),
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
        new HandlerMap([
            TestCommand::class => static fn() => throw new RuntimeException('broken'),
        ]),
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

    expect($transport->size('default'))->toBe(1)
        ->and([...$transport->receive('default')][0]->attempt)->toBe(2);
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
        new HandlerMap([]),
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

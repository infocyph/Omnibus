<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Receiver;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Serialization\DecodeFailure;

test('settlement failures propagate without being treated as handler failures', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $reservation = Reservation::decoded(
        'receipt',
        'default',
        new Envelope(new TestCommand('handled')),
        1,
    );
    $receiver = new class($reservation) implements Receiver {
        public int $released = 0;

        public int $rejected = 0;

        public function __construct(private readonly Reservation $reservation) {}

        public function acknowledge(Reservation $reservation): void
        {
            throw new RuntimeException('acknowledgement unavailable for '.$reservation->receipt);
        }

        public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
        {
            if ($queue === '' || $limit < 1 || $visibilitySeconds <= 0.0) {
                return [];
            }

            return [$this->reservation];
        }

        public function reject(Reservation $reservation): void
        {
            $this->rejected += $reservation->attempt;
        }

        public function release(Reservation $reservation, float $delaySeconds = 0.0): void
        {
            $this->released += $reservation->attempt + (int) $delaySeconds;
        }

        public function size(string $queue): int
        {
            return $queue === 'default' ? 0 : 1;
        }
    };
    $handled = 0;
    $consumer = new Consumer(
        $receiver,
        new HandlerMap([
            TestCommand::class => static function () use (&$handled): void {
                $handled++;
            },
        ]),
        new ExponentialRetryStrategy(maximumAttempts: 10),
        new InMemoryFailureStore(),
        $clock,
    );

    expect(fn() => $consumer->run())
        ->toThrow(RuntimeException::class, 'acknowledgement unavailable')
        ->and($handled)->toBe(1)
        ->and($receiver->released)->toBe(0)
        ->and($receiver->rejected)->toBe(0);
});

test('terminal failure persistence precedes destructive rejection', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('terminal')), 'default');
    $failures = new class implements FailureStore {
        public function add(FailedMessage $failure): void
        {
            throw new RuntimeException('failure store unavailable for '.$failure->id);
        }

        public function all(int $limit = 100): array
        {
            return $limit > 0 ? [] : throw new InvalidArgumentException();
        }

        public function clear(): int
        {
            return 0;
        }

        public function find(string $id): ?FailedMessage
        {
            return $id === '' ? throw new InvalidArgumentException() : null;
        }

        public function prune(DateTimeImmutable $before): int
        {
            return $before->getTimestamp() > 0 ? 0 : 1;
        }

        public function remove(string $id): bool
        {
            return $id === 'present';
        }
    };
    $consumer = new Consumer(
        $transport,
        new HandlerMap([
            TestCommand::class => static fn() => throw new RuntimeException('handler failed'),
        ]),
        new ExponentialRetryStrategy(maximumAttempts: 1),
        $failures,
        $clock,
    );

    expect(fn() => $consumer->run())
        ->toThrow(RuntimeException::class, 'failure store unavailable')
        ->and($transport->size('default'))->toBe(0);

    $clock->advance('+61 seconds');
    expect($transport->size('default'))->toBe(1);
});

test('a missing handler is terminal even when retry capacity remains', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $failures = new InMemoryFailureStore();
    $sent = $transport->send(new Envelope(new TestCommand('unmapped')), 'default');
    $consumer = new Consumer(
        $transport,
        new HandlerMap([]),
        new ExponentialRetryStrategy(maximumAttempts: 100),
        $failures,
        $clock,
    );

    $result = $consumer->run();

    expect($result->failed)->toBe(1)
        ->and($result->released)->toBe(0)
        ->and($transport->size('default'))->toBe(0)
        ->and($failures->all())->toHaveCount(1)
        ->and($failures->all()[0]->envelope?->message)->toBe($sent->message);
});

test('oversized provider receipts use a stable bounded failure identifier', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $receipt = str_repeat('r', 2_000);
    $receiver = new class($receipt) implements Receiver {
        public function __construct(private readonly string $receipt) {}

        public function acknowledge(Reservation $reservation): void {}

        public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
        {
            if ($queue === '' || $limit < 1 || $visibilitySeconds <= 0.0) {
                return [];
            }

            return [
                Reservation::undecodable(
                    $this->receipt,
                    $queue,
                    new DecodeFailure('raw', JsonException::class, 'invalid'),
                    1,
                ),
            ];
        }

        public function reject(Reservation $reservation): void {}

        public function release(Reservation $reservation, float $delaySeconds = 0.0): void {}

        public function size(string $queue): int
        {
            return $queue === 'provider' ? 0 : 1;
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

    $consumer->run(queue: 'provider');
    $failure = $failures->all()[0];

    expect($failure->id)->toStartWith('receipt-')
        ->and(strlen($failure->id))->toBeLessThanOrEqual(191)
        ->and($failure->payload)->toBe('raw');
});

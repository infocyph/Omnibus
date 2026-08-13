<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\EnqueuedAtStamp;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Failure\FailureRemovalAfterRetryFailed;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureNotFound;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Failure\UndecodableFailure;
use Infocyph\Omnibus\Failure\WorkflowFailureRequiresRecovery;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Telemetry\ObservedExecutionScope;
use Infocyph\Omnibus\Telemetry\ObservedTransport;
use Infocyph\Omnibus\Telemetry\TelemetrySink;
use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('failure manager retries decoded messages only after a successful send', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryFailureStore();
    $store->add(FailedMessage::decoded(
        'decoded',
        'work',
        new Envelope(new TestCommand('retry')),
        1,
        $clock->now(),
        RuntimeException::class,
        'down',
    ));
    $store->add(FailedMessage::undecodable(
        'raw',
        'work',
        '{broken',
        1,
        $clock->now(),
        JsonException::class,
        'Syntax error',
    ));
    $manager = new FailureManager($store);
    $sender = new RecordingSender();

    $manager->retry('decoded', $sender);

    expect($sender->count())->toBe(1)
        ->and($store->find('decoded'))->toBeNull()
        ->and(fn() => $manager->retry('missing', $sender))
        ->toThrow(FailureNotFound::class)
        ->and(fn() => $manager->retry('raw', $sender))
        ->toThrow(UndecodableFailure::class)
        ->and($manager->forget('raw'))->toBeTrue()
        ->and($manager->flush())->toBe(0);
});

test('manual retry strips lifecycle state and rejects workflow replay', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $store = new InMemoryFailureStore();
    $store->add(FailedMessage::decoded(
        'normal',
        'work',
        new Envelope(new TestCommand('retry'), [
            new MessageIdStamp('stable-id'),
            new AttemptStamp(4),
            new EnqueuedAtStamp(1),
            new UniqueStamp('key', 'token', 30),
            new RouteStamp('old', 'old'),
            new DelayStamp(10),
            new HandledStamp('old'),
        ]),
        4,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));
    $store->add(FailedMessage::decoded(
        'workflow',
        'work',
        new Envelope(new TestCommand('workflow'), [
            new BatchStamp('workflow-id', 'item-id', 0),
        ]),
        1,
        $clock->now(),
        RuntimeException::class,
        'failed',
    ));
    $sender = new RecordingSender();
    $manager = new FailureManager($store);

    $manager->retry('normal', $sender);
    $replayed = $sender->sent()[0]['envelope'];

    expect($replayed->last(MessageIdStamp::class)?->id)->toBe('stable-id')
        ->and($replayed->last(AttemptStamp::class))->toBeNull()
        ->and($replayed->last(EnqueuedAtStamp::class))->toBeNull()
        ->and($replayed->last(UniqueStamp::class))->toBeNull()
        ->and($replayed->last(RouteStamp::class))->toBeNull()
        ->and($replayed->last(DelayStamp::class))->toBeNull()
        ->and($replayed->last(HandledStamp::class))->toBeNull()
        ->and(fn() => $manager->retry('workflow', $sender))
        ->toThrow(WorkflowFailureRequiresRecovery::class);
});

test('post-send failure removal is explicit and failure reasons are bounded', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $failure = FailedMessage::decoded(
        'stuck',
        'work',
        new Envelope(new TestCommand('retry')),
        1,
        $clock->now(),
        RuntimeException::class,
        str_repeat('x', 20_000),
    );
    $store = new class($failure) implements FailureStore {
        public function __construct(private readonly FailedMessage $failure) {}

        public function add(FailedMessage $failure): void {}

        public function all(int $limit = 100): array
        {
            return $limit > 0 ? [$this->failure] : [];
        }

        public function clear(): int
        {
            return 0;
        }

        public function find(string $id): ?FailedMessage
        {
            return $id === $this->failure->id ? $this->failure : null;
        }

        public function prune(DateTimeImmutable $before): int
        {
            return $before > $this->failure->failedAt ? 1 : 0;
        }

        public function remove(string $id): bool
        {
            return $id === $this->failure->id && false;
        }
    };
    $sender = new RecordingSender();

    expect(strlen($failure->reason))->toBe(16_384)
        ->and(fn() => (new FailureManager($store))->retry('stuck', $sender))
        ->toThrow(FailureRemovalAfterRetryFailed::class)
        ->and($sender->count())->toBe(1);
});

test('failure inputs reject unsafe direct IDs and share one reason bound', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $reason = str_repeat('r', 20_000);
    $decoded = new DecodeFailure('raw', JsonException::class, $reason);

    expect(strlen($decoded->reason))->toBe(16_384)
        ->and(fn() => FailedMessage::undecodable(
            "unsafe\nid",
            'work',
            'raw',
            1,
            $clock->now(),
            JsonException::class,
            $reason,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn() => FailedMessage::undecodable(
            str_repeat('i', 192),
            'work',
            'raw',
            1,
            $clock->now(),
            JsonException::class,
            $reason,
        ))->toThrow(InvalidArgumentException::class);
});

test('telemetry decorators expose queue and execution measurements only when selected', function (): void {
    $metrics = [];
    $sink = new class($metrics) implements TelemetrySink {
        /** @var list<string> */
        public array $metrics;

        /** @param list<string> $metrics */
        public function __construct(array &$metrics)
        {
            $this->metrics = &$metrics;
        }

        public function record(string $metric, float|int $value, array $attributes = []): void
        {
            $this->metrics[] = sprintf('%s:%s:%d', $metric, (string) $value, count($attributes));
        }
    };
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new ObservedTransport(
        new InMemoryTransport($clock),
        $sink,
        $clock,
        'memory',
    );
    $transport->send(new Envelope(new TestCommand('observed')), 'work');
    $clock->advance('+1 second');
    $reservation = [...$transport->receive('work')][0];
    $scope = new ObservedExecutionScope(new DirectExecutionScope(), $sink);
    $scope->run($reservation->envelope(), static fn(): null => null);
    $transport->acknowledge($reservation);
    $transport->size('work');

    expect(array_filter(
        $metrics,
        static fn(string $metric): bool => str_starts_with($metric, 'queue.age_ms:'),
    ))->not->toBe([])
        ->and(array_filter(
            $metrics,
            static fn(string $metric): bool => str_starts_with($metric, 'queue.processing.succeeded:'),
        ))->not->toBe([]);
});

test('telemetry exporter failures never change queue failure or handler outcomes', function (): void {
    $telemetry = new class implements TelemetrySink {
        public function record(string $metric, float|int $value, array $attributes = []): void
        {
            throw new RuntimeException(sprintf(
                'telemetry unavailable for %s=%s with %d attributes',
                $metric,
                (string) $value,
                count($attributes),
            ));
        }
    };
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new ObservedTransport(new InMemoryTransport($clock), $telemetry, $clock, 'memory');
    $sent = $transport->send(new Envelope(new TestCommand('safe')), 'work');
    $reservation = [...$transport->receive('work')][0];
    $transport->acknowledge($reservation);

    $store = new InMemoryFailureStore();
    $observedFailures = new Infocyph\Omnibus\Telemetry\ObservedFailureStore($store, $telemetry);
    $observedFailures->add(FailedMessage::decoded(
        'failed',
        'work',
        $sent,
        1,
        $clock->now(),
        RuntimeException::class,
        'handler failed',
    ));
    $scope = new ObservedExecutionScope(new DirectExecutionScope(), $telemetry);

    expect($transport->size('work'))->toBe(0)
        ->and($store->find('failed'))->not->toBeNull()
        ->and(fn() => $scope->run(
            new Envelope(new TestCommand('handler')),
            static fn() => throw new DomainException('original handler failure'),
        ))->toThrow(DomainException::class, 'original handler failure')
        ->and($scope->run(
            new Envelope(new TestCommand('success')),
            static fn(): string => 'result',
        ))->toBe('result');
});

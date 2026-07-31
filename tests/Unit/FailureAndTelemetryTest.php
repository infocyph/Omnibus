<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureNotFound;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Failure\UndecodableFailure;
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
        static fn(string $metric): bool => str_starts_with($metric, 'queue.wait_ms:'),
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

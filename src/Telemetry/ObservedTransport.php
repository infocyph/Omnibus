<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Telemetry;

use Infocyph\Omnibus\Envelope\EnqueuedAtStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;
use Psr\Clock\ClockInterface;

final readonly class ObservedTransport implements Transport
{
    public function __construct(
        private Transport $inner,
        private TelemetrySink $telemetry,
        private ClockInterface $clock,
        private string $transport,
    ) {
        if ($transport === '') {
            throw new \InvalidArgumentException('Observed transport name cannot be empty.');
        }
    }

    public function acknowledge(Reservation $reservation): void
    {
        $this->inner->acknowledge($reservation);
        $this->record('queue.acknowledged', 1, $this->attributes($reservation->queue));
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        $started = hrtime(true);
        $reservations = [...$this->inner->receive($queue, $limit, $visibilitySeconds)];
        $this->record(
            'queue.receive.duration_ms',
            (hrtime(true) - $started) / 1_000_000,
            $this->attributes($queue),
        );
        $this->record('queue.received', count($reservations), $this->attributes($queue));
        $now = $this->microseconds();
        foreach ($reservations as $reservation) {
            $this->record(
                'queue.attempt',
                $reservation->attempt,
                $this->attributes($queue),
            );
            $failure = $reservation->decodingFailure();
            if ($failure !== null) {
                continue;
            }
            $enqueued = $reservation->envelope()->last(EnqueuedAtStamp::class);
            if ($enqueued instanceof EnqueuedAtStamp) {
                $this->record(
                    'queue.wait_ms',
                    max(0, $now - $enqueued->microseconds) / 1_000,
                    $this->attributes($queue),
                );
                $this->record(
                    'queue.age_ms',
                    max(0, $now - $enqueued->microseconds) / 1_000,
                    $this->attributes($queue),
                );
            }
        }

        return $reservations;
    }

    public function reject(Reservation $reservation): void
    {
        $this->inner->reject($reservation);
        $this->record('queue.rejected', 1, $this->attributes($reservation->queue));
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        $this->inner->release($reservation, $delaySeconds);
        $this->record('queue.released', 1, $this->attributes($reservation->queue));
        $this->record('queue.retry_delay_ms', $delaySeconds * 1_000, $this->attributes(
            $reservation->queue,
        ));
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        if (!$envelope->last(EnqueuedAtStamp::class) instanceof EnqueuedAtStamp) {
            $envelope = $envelope->with(new EnqueuedAtStamp($this->microseconds()));
        }
        $started = hrtime(true);
        $sent = $this->inner->send($envelope, $queue);
        $this->record(
            'queue.enqueue.duration_ms',
            (hrtime(true) - $started) / 1_000_000,
            $this->attributes($queue),
        );
        $this->record('queue.enqueued', 1, $this->attributes($queue));

        return $sent;
    }

    public function size(string $queue): int
    {
        $depth = $this->inner->size($queue);
        $this->record('queue.depth', $depth, $this->attributes($queue));

        return $depth;
    }

    /** @return array{transport:string,queue:string} */
    private function attributes(string $queue): array
    {
        return ['transport' => $this->transport, 'queue' => $queue];
    }

    private function microseconds(): int
    {
        $now = $this->clock->now();

        return ((int) $now->format('U')) * 1_000_000 + (int) $now->format('u');
    }

    /** @param array<string, bool|float|int|string> $attributes */
    private function record(string $metric, float|int $value, array $attributes): void
    {
        try {
            $this->telemetry->record($metric, $value, $attributes);
        } catch (\Throwable) {
            // Telemetry is observational and must not alter message settlement.
        }
    }
}

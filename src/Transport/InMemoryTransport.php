<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final class InMemoryTransport implements Transport
{
    /**
     * @var array<string, list<array{
     *     envelope: Envelope,
     *     available_at: float,
     *     attempt: int
     * }>>
     */
    private array $queues = [];

    /**
     * @var array<string, array{
     *     envelope: Envelope,
     *     queue: string,
     *     expires_at: float,
     *     attempt: int
     * }>
     */
    private array $reserved = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function acknowledge(Reservation $reservation): void
    {
        $this->forget($reservation);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        if ($limit < 1 || !is_finite($visibilitySeconds) || $visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException('Receive limit and visibility timeout must be positive.');
        }

        $now = $this->timestamp();
        $this->restoreExpired($now);
        $pending = $this->queues[$queue] ?? [];
        if ($pending === []) {
            return [];
        }

        $deliveries = [];
        $remaining = [];
        foreach ($pending as $item) {
            if (count($deliveries) >= $limit || $item['available_at'] > $now) {
                $remaining[] = $item;

                continue;
            }

            $attempt = $item['attempt'] + 1;
            $receipt = ULID::generateMonotonic();
            $envelope = $item['envelope']->with(new AttemptStamp($attempt));
            $this->reserved[$receipt] = [
                'envelope' => $envelope,
                'queue' => $queue,
                'expires_at' => $now + $visibilitySeconds,
                'attempt' => $attempt,
            ];
            $deliveries[] = Reservation::decoded($receipt, $queue, $envelope, $attempt);
        }
        $this->queues[$queue] = $remaining;

        return $deliveries;
    }

    public function reject(Reservation $reservation): void
    {
        $this->forget($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Release delay must be a finite non-negative number.');
        }

        $item = $this->forget($reservation);
        $this->queues[$reservation->queue][] = [
            'envelope' => $item['envelope'],
            'available_at' => $this->timestamp() + $delaySeconds,
            'attempt' => $item['attempt'],
        ];
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $delayStamp = $envelope->last(DelayStamp::class);
        $delay = $delayStamp instanceof DelayStamp ? $delayStamp->seconds : 0.0;
        $this->queues[$queue][] = [
            'envelope' => $envelope,
            'available_at' => $this->timestamp() + $delay,
            'attempt' => 0,
        ];

        return $envelope;
    }

    public function size(string $queue): int
    {
        $now = $this->timestamp();
        $this->restoreExpired($now);
        $ready = 0;
        foreach ($this->queues[$queue] ?? [] as $item) {
            $ready += $item['available_at'] <= $now ? 1 : 0;
        }

        return $ready;
    }

    /**
     * @return array{
     *     envelope: Envelope,
     *     queue: string,
     *     expires_at: float,
     *     attempt: int
     * }
     */
    private function forget(Reservation $reservation): array
    {
        $item = $this->reserved[$reservation->receipt] ?? null;
        if ($item === null || $item['queue'] !== $reservation->queue) {
            throw new InvalidReservation(sprintf('Reservation "%s" is no longer active.', $reservation->receipt));
        }

        unset($this->reserved[$reservation->receipt]);

        return $item;
    }

    private function restoreExpired(float $now): void
    {
        foreach ($this->reserved as $receipt => $item) {
            if ($item['expires_at'] > $now) {
                continue;
            }
            $this->queues[$item['queue']][] = [
                'envelope' => $item['envelope'],
                'available_at' => $now,
                'attempt' => $item['attempt'],
            ];
            unset($this->reserved[$receipt]);
        }
    }

    private function timestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}

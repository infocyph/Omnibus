<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;

final readonly class UniqueTransport implements Transport
{
    public function __construct(
        private Transport $inner,
        private DetachedLeaseProvider $locks,
    ) {}

    public function acknowledge(Reservation $reservation): void
    {
        $this->inner->acknowledge($reservation);
        $this->releaseLease($reservation);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        return $this->inner->receive($queue, $limit, $visibilitySeconds);
    }

    public function reject(Reservation $reservation): void
    {
        $this->inner->reject($reservation);
        $this->releaseLease($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        $handle = $this->handle($reservation);
        if (
            $handle instanceof LockHandle
            && !$this->locks->refresh($handle, $handle->leaseSeconds)
        ) {
            throw new LeaseLost(sprintf('Unique-message lease "%s" was lost.', $handle->key));
        }
        $this->inner->release($reservation, $delaySeconds);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        return $this->inner->send($envelope, $queue);
    }

    public function size(string $queue): int
    {
        return $this->inner->size($queue);
    }

    private function handle(Reservation $reservation): ?LockHandle
    {
        if ($reservation->decodingFailure() !== null) {
            return null;
        }
        $stamp = $reservation->envelope()->last(UniqueStamp::class);

        return $stamp instanceof UniqueStamp
            ? new LockHandle($stamp->key, $stamp->token, leaseSeconds: $stamp->leaseSeconds)
            : null;
    }

    private function releaseLease(Reservation $reservation): void
    {
        $this->locks->release($this->handle($reservation));
    }
}

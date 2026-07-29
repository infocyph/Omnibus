<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;

final readonly class WorkflowTransport implements Transport
{
    public function __construct(
        private Transport $inner,
        private WorkflowCoordinator $workflows,
    ) {}

    public function acknowledge(Reservation $reservation): void
    {
        if ($reservation->decodingFailure() === null) {
            $this->workflows->succeed($reservation->envelope());
        }
        $this->inner->acknowledge($reservation);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        return $this->inner->receive($queue, $limit, $visibilitySeconds);
    }

    public function reject(Reservation $reservation): void
    {
        $this->inner->reject($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
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
}

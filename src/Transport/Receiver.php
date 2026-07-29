<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

interface Receiver
{
    public function acknowledge(Reservation $reservation): void;

    /** @return iterable<Reservation> */
    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable;

    public function reject(Reservation $reservation): void;

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void;

    public function size(string $queue): int;
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class Reservation
{
    public function __construct(
        public string $receipt,
        public string $queue,
        public Envelope $envelope,
        public int $attempt,
    ) {
        if ($receipt === '' || $queue === '' || $attempt < 1) {
            throw new \InvalidArgumentException('A reservation requires a receipt, queue, and positive attempt.');
        }
    }
}

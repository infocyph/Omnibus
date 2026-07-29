<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Broker;

final readonly class BrokerDelivery
{
    public function __construct(
        public string $receipt,
        public string $payload,
        public int $attempt,
    ) {
        if ($receipt === '' || $attempt < 1) {
            throw new \InvalidArgumentException('Broker delivery requires a receipt and positive attempt.');
        }
    }
}

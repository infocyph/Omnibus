<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Broker;

final readonly class BrokerCapabilities
{
    public function __construct(
        public bool $delayedDelivery,
        public bool $delayedRelease,
        public bool $exactSize,
        public int $maximumBatch,
    ) {
        if ($maximumBatch < 1) {
            throw new \InvalidArgumentException('Broker maximum batch must be positive.');
        }
    }
}

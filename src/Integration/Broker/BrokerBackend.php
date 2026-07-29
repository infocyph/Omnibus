<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Broker;

interface BrokerBackend
{
    public function acknowledge(string $queue, string $receipt): void;

    public function capabilities(): BrokerCapabilities;

    /** @return list<BrokerDelivery> */
    public function receive(
        string $queue,
        int $limit,
        float $visibilitySeconds,
    ): array;

    public function reject(string $queue, string $receipt): void;

    public function release(string $queue, string $receipt, float $delaySeconds): void;

    public function send(
        string $queue,
        string $messageId,
        string $payload,
        float $delaySeconds,
    ): void;

    /**
     * Exactness is declared by BrokerCapabilities::exactSize.
     */
    public function size(string $queue): int;
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Broker;

final readonly class BrokerDelivery
{
    public function __construct(
        public string $receipt,
        public string $messageId,
        public string $payload,
        public int $attempt,
    ) {
        if (
            $receipt === ''
            || $messageId === ''
            || strlen($messageId) > 191
            || preg_match('/[\x00-\x1F\x7F]/D', $messageId) === 1
            || $attempt < 1
        ) {
            throw new \InvalidArgumentException('Broker delivery requires receipt/message IDs and positive attempt.');
        }
    }
}

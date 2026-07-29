<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Integration\AMQP\AmqpBackend;
use Infocyph\Omnibus\Integration\Broker\BrokerCapabilities;
use Infocyph\Omnibus\Integration\Broker\BrokerDelivery;
use Infocyph\Omnibus\Integration\SQS\SqsBackend;

final class RecordingBrokerBackend implements AmqpBackend, SqsBackend
{
    /** @var list<array{queue:string,id:string,payload:string,delay:float}> */
    public array $sent = [];

    /** @var list<BrokerDelivery> */
    public array $deliveries = [];

    /** @var list<string> */
    public array $settled = [];

    public function __construct(private readonly BrokerCapabilities $features) {}

    public function acknowledge(string $queue, string $receipt): void
    {
        $this->settled[] = 'ack:'.$queue.':'.$receipt;
    }

    public function capabilities(): BrokerCapabilities
    {
        return $this->features;
    }

    public function receive(string $queue, int $limit, float $visibilitySeconds): array
    {
        if ($queue === '' || $visibilitySeconds <= 0.0) {
            return [];
        }

        return array_slice($this->deliveries, 0, $limit);
    }

    public function reject(string $queue, string $receipt): void
    {
        $this->settled[] = 'reject:'.$queue.':'.$receipt;
    }

    public function release(string $queue, string $receipt, float $delaySeconds): void
    {
        $this->settled[] = sprintf('release:%s:%s:%.1f', $queue, $receipt, $delaySeconds);
    }

    public function send(
        string $queue,
        string $messageId,
        string $payload,
        float $delaySeconds,
    ): void {
        $this->sent[] = [
            'queue' => $queue,
            'id' => $messageId,
            'payload' => $payload,
            'delay' => $delaySeconds,
        ];
    }

    public function size(string $queue): int
    {
        return $queue === '' ? 0 : count($this->deliveries);
    }
}

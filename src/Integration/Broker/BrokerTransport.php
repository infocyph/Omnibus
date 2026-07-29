<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Broker;

use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\UID\ULID;

class BrokerTransport implements Transport
{
    public function __construct(
        private readonly BrokerBackend $backend,
        private readonly EnvelopeSerializer $serializer,
    ) {}

    public function acknowledge(Reservation $reservation): void
    {
        $this->backend->acknowledge($reservation->queue, $reservation->receipt);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        if ($queue === '' || $limit < 1 || !is_finite($visibilitySeconds) || $visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Receive requires a queue, positive limit, and positive visibility timeout.',
            );
        }
        $capabilities = $this->backend->capabilities();
        if ($limit > $capabilities->maximumBatch) {
            throw new \InvalidArgumentException(sprintf(
                'This broker accepts at most %d messages per receive call.',
                $capabilities->maximumBatch,
            ));
        }

        $reservations = [];
        foreach ($this->backend->receive($queue, $limit, $visibilitySeconds) as $delivery) {
            try {
                $envelope = $this->serializer
                    ->decode($delivery->payload)
                    ->with(new AttemptStamp($delivery->attempt));
                $reservations[] = Reservation::decoded(
                    $delivery->receipt,
                    $queue,
                    $envelope,
                    $delivery->attempt,
                );
            } catch (\Throwable $failure) {
                $reservations[] = Reservation::undecodable(
                    $delivery->receipt,
                    $queue,
                    DecodeFailure::fromThrowable($delivery->payload, $failure),
                    $delivery->attempt,
                );
            }
        }

        return $reservations;
    }

    public function reject(Reservation $reservation): void
    {
        $this->backend->reject($reservation->queue, $reservation->receipt);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Release delay must be a finite non-negative number.');
        }
        if ($delaySeconds > 0.0 && !$this->backend->capabilities()->delayedRelease) {
            throw new UnsupportedBrokerCapability('This broker adapter does not support delayed release.');
        }

        $this->backend->release($reservation->queue, $reservation->receipt, $delaySeconds);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        if ($queue === '') {
            throw new \InvalidArgumentException('Queue name cannot be empty.');
        }
        $messageId = $envelope->last(MessageIdStamp::class);
        if (!$messageId instanceof MessageIdStamp) {
            $messageId = new MessageIdStamp(ULID::generateMonotonic());
            $envelope = $envelope->with($messageId);
        }
        $delay = $envelope->last(DelayStamp::class);
        $delaySeconds = $delay instanceof DelayStamp ? $delay->seconds : 0.0;
        if ($delaySeconds > 0.0 && !$this->backend->capabilities()->delayedDelivery) {
            throw new UnsupportedBrokerCapability('This broker adapter does not support delayed delivery.');
        }
        $this->backend->send(
            $queue,
            $messageId->id,
            $this->serializer->encode($envelope),
            $delaySeconds,
        );

        return $envelope;
    }

    public function size(string $queue): int
    {
        return $this->backend->size($queue);
    }
}

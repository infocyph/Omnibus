<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Serialization\DecodeFailure;

final readonly class Reservation
{
    private function __construct(
        public string $receipt,
        public string $queue,
        public int $attempt,
        public string $messageId,
        private ?Envelope $decodedEnvelope,
        private ?DecodeFailure $decodeFailure,
    ) {
        if (
            $receipt === ''
            || strlen($receipt) > 4_096
            || $messageId === ''
            || strlen($messageId) > 191
            || preg_match('/[\x00-\x1F\x7F]/D', $messageId) === 1
            || $attempt < 1
            || (($decodedEnvelope === null) === ($decodeFailure === null))
        ) {
            throw new \InvalidArgumentException('A reservation requires a receipt, queue, and positive attempt.');
        }
        QueueName::assert($queue);
    }

    public static function decoded(
        string $receipt,
        string $queue,
        Envelope $envelope,
        int $attempt,
        string $messageId,
    ): self {
        return new self($receipt, $queue, $attempt, $messageId, $envelope, null);
    }

    public static function undecodable(
        string $receipt,
        string $queue,
        DecodeFailure $failure,
        int $attempt,
        string $messageId,
    ): self {
        return new self($receipt, $queue, $attempt, $messageId, null, $failure);
    }

    public function decodingFailure(): ?DecodeFailure
    {
        return $this->decodeFailure;
    }

    public function envelope(): Envelope
    {
        return $this->decodedEnvelope
            ?? throw new \LogicException('The reserved payload could not be decoded.');
    }
}

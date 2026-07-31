<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\QueueName;

final readonly class FailedMessage
{
    private function __construct(
        public string $id,
        public string $queue,
        public ?Envelope $envelope,
        public ?string $payload,
        public int $attempt,
        public \DateTimeImmutable $failedAt,
        public string $failureClass,
        public string $reason,
        public bool $payloadTruncated,
    ) {
        if (
            $id === ''
            || strlen($id) > 191
            || $attempt < 1
            || $failureClass === ''
            || strlen($failureClass) > 255
            || (($envelope === null) === ($payload === null))
        ) {
            throw new \InvalidArgumentException('A failed message requires one decoded envelope or raw payload.');
        }
        QueueName::assert($queue);
    }

    public static function decoded(
        string $id,
        string $queue,
        Envelope $envelope,
        int $attempt,
        \DateTimeImmutable $failedAt,
        string $failureClass,
        string $reason,
    ): self {
        return new self(
            $id,
            $queue,
            $envelope,
            null,
            $attempt,
            $failedAt,
            $failureClass,
            $reason,
            false,
        );
    }

    public static function undecodable(
        string $id,
        string $queue,
        string $payload,
        int $attempt,
        \DateTimeImmutable $failedAt,
        string $failureClass,
        string $reason,
        bool $payloadTruncated = false,
    ): self {
        return new self(
            $id,
            $queue,
            null,
            $payload,
            $attempt,
            $failedAt,
            $failureClass,
            $reason,
            $payloadTruncated,
        );
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class FailedMessage
{
    public function __construct(
        public string $id,
        public string $queue,
        public Envelope $envelope,
        public int $attempt,
        public \DateTimeImmutable $failedAt,
        public string $failureClass,
        public string $reason,
    ) {}
}

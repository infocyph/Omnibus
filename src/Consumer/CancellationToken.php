<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Psr\Clock\ClockInterface;

final readonly class CancellationToken
{
    public function __construct(
        private ClockInterface $clock,
        public \DateTimeImmutable $deadline,
    ) {}

    public function isCancellationRequested(): bool
    {
        return $this->clock->now() >= $this->deadline;
    }

    public function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw new ExecutionTimedOut($this->deadline);
        }
    }
}

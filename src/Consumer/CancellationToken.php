<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Closure;
use Psr\Clock\ClockInterface;

final readonly class CancellationToken
{
    /** @var (Closure():bool)|null */
    private ?Closure $externalCancellation;

    /** @param callable():bool|null $externalCancellation */
    public function __construct(
        private ClockInterface $clock,
        public \DateTimeImmutable $deadline,
        ?callable $externalCancellation = null,
    ) {
        $this->externalCancellation = $externalCancellation === null
            ? null
            : Closure::fromCallable($externalCancellation);
    }

    public function isCancellationRequested(): bool
    {
        return $this->clock->now() >= $this->deadline
            || ($this->externalCancellation instanceof Closure && ($this->externalCancellation)());
    }

    public function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw new ExecutionTimedOut($this->deadline);
        }
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $time)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $interval): void
    {
        $next = $this->time->modify($interval);
        if (!$next instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Invalid clock interval.');
        }
        $this->time = $next;
    }
}

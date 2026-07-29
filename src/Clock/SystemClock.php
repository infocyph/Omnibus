<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Clock;

use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

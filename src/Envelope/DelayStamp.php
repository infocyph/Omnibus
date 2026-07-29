<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class DelayStamp implements Stamp
{
    public function __construct(public float $seconds)
    {
        if (!is_finite($seconds) || $seconds < 0.0) {
            throw new \InvalidArgumentException('Message delay must be a finite non-negative number.');
        }
    }
}

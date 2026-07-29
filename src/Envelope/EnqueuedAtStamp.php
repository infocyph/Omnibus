<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class EnqueuedAtStamp implements Stamp
{
    public function __construct(public int $microseconds)
    {
        if ($microseconds < 0) {
            throw new \InvalidArgumentException('Enqueue timestamp cannot be negative.');
        }
    }
}

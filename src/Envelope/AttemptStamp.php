<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class AttemptStamp implements Stamp
{
    public function __construct(public int $attempt)
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('A delivery attempt must be at least one.');
        }
    }
}

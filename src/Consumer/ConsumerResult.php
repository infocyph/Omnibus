<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

final readonly class ConsumerResult
{
    public function __construct(
        public int $received,
        public int $succeeded,
        public int $released,
        public int $failed,
    ) {
        if (
            min($received, $succeeded, $released, $failed) < 0
            || $received !== $succeeded + $released + $failed
        ) {
            throw new \InvalidArgumentException('Consumer result counters are inconsistent.');
        }
    }
}

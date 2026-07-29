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
    ) {}
}

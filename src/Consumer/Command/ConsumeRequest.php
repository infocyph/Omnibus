<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer\Command;

final readonly class ConsumeRequest
{
    public function __construct(
        public string $queue = 'default',
        public int $limit = 1,
        public float $visibilitySeconds = 60.0,
    ) {
        if ($queue === '' || $limit < 1 || !is_finite($visibilitySeconds) || $visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Consume request requires a queue, positive limit, and positive visibility timeout.',
            );
        }
    }
}

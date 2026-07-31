<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer\Command;

use Infocyph\Omnibus\Transport\QueueName;

final readonly class ConsumeRequest
{
    public function __construct(
        public string $queue = 'default',
        public int $limit = 1,
        public float $visibilitySeconds = 60.0,
    ) {
        QueueName::assert($queue);
        if (
            $limit < 1
            || $limit > 1_000
            || !is_finite($visibilitySeconds)
            || $visibilitySeconds <= 0.0
        ) {
            throw new \InvalidArgumentException(
                'Consume request requires a limit between 1 and 1000 and a positive visibility timeout.',
            );
        }
    }
}

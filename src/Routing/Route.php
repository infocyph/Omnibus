<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Routing;

use Infocyph\Omnibus\Transport\QueueName;

final readonly class Route
{
    public function __construct(
        public string $transport = 'sync',
        public string $queue = 'default',
        public float $delaySeconds = 0.0,
    ) {
        if (
            $transport === ''
            || strlen($transport) > 100
            || preg_match('/[\x00-\x1F\x7F]/D', $transport) === 1
        ) {
            throw new \InvalidArgumentException(
                'Transport names must contain between 1 and 100 bytes without control characters.',
            );
        }
        QueueName::assert($queue);
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Route delay must be a finite non-negative number.');
        }
    }
}

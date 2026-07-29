<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Routing;

final readonly class Route
{
    public function __construct(
        public string $transport = 'sync',
        public string $queue = 'default',
        public float $delaySeconds = 0.0,
    ) {
        if ($transport === '' || $queue === '') {
            throw new \InvalidArgumentException('Transport and queue names cannot be empty.');
        }
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Route delay must be a finite non-negative number.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class RouteStamp implements Stamp
{
    public function __construct(
        public string $transport,
        public string $queue,
    ) {
        if ($transport === '' || $queue === '') {
            throw new \InvalidArgumentException('Transport and queue names cannot be empty.');
        }
    }
}

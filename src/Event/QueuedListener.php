<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

final readonly class QueuedListener
{
    /** @param class-string<ShouldQueue> $listener */
    public function __construct(
        public string $listener,
        public object $event,
    ) {}
}

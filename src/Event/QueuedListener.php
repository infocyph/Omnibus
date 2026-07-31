<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

final readonly class QueuedListener
{
    /** @param class-string<ShouldQueue> $listener */
    public function __construct(
        public string $listener,
        public object $event,
    ) {
        self::validateType($listener);
    }

    private static function validateType(string $listener): void
    {
        if (!is_a($listener, ShouldQueue::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Queued listener "%s" must implement %s.',
                $listener,
                ShouldQueue::class,
            ));
        }
    }
}

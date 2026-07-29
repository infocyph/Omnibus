<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

final readonly class QueuedListenerResolver
{
    /** @param array<class-string<ShouldQueue>, callable> $listeners */
    public function __construct(private array $listeners) {}

    public function resolve(string $listener): callable
    {
        return $this->listeners[$listener]
            ?? throw new QueuedListenerNotConfigured(
                sprintf('Queued listener "%s" is not registered.', $listener),
            );
    }
}

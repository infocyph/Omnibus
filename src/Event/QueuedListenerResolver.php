<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

final readonly class QueuedListenerResolver
{
    /** @param array<class-string<ShouldQueue>, callable> $listeners */
    public function __construct(private array $listeners)
    {
        foreach ($listeners as $type => $listener) {
            self::validateMapping($type, $listener);
        }
    }

    public function resolve(string $listener): callable
    {
        return $this->listeners[$listener]
            ?? throw new QueuedListenerNotConfigured(
                sprintf('Queued listener "%s" is not registered.', $listener),
            );
    }

    private static function validateMapping(mixed $type, mixed $listener): void
    {
        if (!is_string($type) || !is_a($type, ShouldQueue::class, true) || !is_callable($listener)) {
            throw new \InvalidArgumentException(
                'Queued-listener mappings require ShouldQueue types and callables.',
            );
        }
    }
}

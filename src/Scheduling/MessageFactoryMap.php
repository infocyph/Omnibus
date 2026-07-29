<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Scheduling;

final readonly class MessageFactoryMap
{
    /** @param array<string, callable(): object> $factories */
    public function __construct(private array $factories) {}

    public function create(string $key): object
    {
        $factory = $this->factories[$key]
            ?? throw new MessageFactoryNotFound(
                sprintf('Scheduled message factory "%s" is not registered.', $key),
            );

        return $factory();
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

final readonly class TransportRegistry
{
    /** @param array<string, Sender> $transports */
    public function __construct(private array $transports) {}

    public function get(string $name): Sender
    {
        return $this->transports[$name]
            ?? throw new TransportNotFound(sprintf('Transport "%s" is not registered.', $name));
    }
}

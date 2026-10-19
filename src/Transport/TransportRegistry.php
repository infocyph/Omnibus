<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

final readonly class TransportRegistry
{
    /** @param array<string, Sender|(\Closure(): Sender)> $transports */
    public function __construct(private array $transports)
    {
        foreach ($transports as $name => $transport) {
            self::validateMapping($name, $transport);
        }
    }

    public function get(string $name): Sender
    {
        $transport = $this->transports[$name]
            ?? throw new TransportNotFound(sprintf('Transport "%s" is not registered.', $name));

        if ($transport instanceof \Closure) {
            return $transport();
        }

        return $transport;
    }

    private static function validateMapping(mixed $name, mixed $transport): void
    {
        if (
            !is_string($name)
            || $name === ''
            || strlen($name) > 100
            || preg_match('/[\x00-\x1F\x7F]/D', $name) === 1
            || (!$transport instanceof Sender && !$transport instanceof \Closure)
        ) {
            throw new \InvalidArgumentException(
                'Transport mappings require bounded names and Sender implementations.',
            );
        }
    }
}

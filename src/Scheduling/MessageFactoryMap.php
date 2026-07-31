<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Scheduling;

final readonly class MessageFactoryMap
{
    /** @param array<string, callable(): object> $factories */
    public function __construct(private array $factories)
    {
        foreach ($factories as $key => $factory) {
            self::validateMapping($key, $factory);
        }
    }

    public function create(string $key): object
    {
        $factory = $this->factories[$key]
            ?? throw new MessageFactoryNotFound(
                sprintf('Scheduled message factory "%s" is not registered.', $key),
            );

        return $factory();
    }

    private static function validateMapping(mixed $key, mixed $factory): void
    {
        if (
            !is_string($key)
            || $key === ''
            || strlen($key) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $key) === 1
            || !is_callable($factory)
        ) {
            throw new \InvalidArgumentException(
                'Scheduled-message mappings require bounded keys and callable factories.',
            );
        }
    }
}

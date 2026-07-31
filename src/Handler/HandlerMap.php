<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Handler;

final class HandlerMap
{
    /** @var array<class-string, callable> */
    private array $resolved = [];

    /** @param array<class-string, callable> $handlers */
    public function __construct(private array $handlers)
    {
        foreach ($handlers as $type => $handler) {
            self::validateMapping($type, $handler);
        }
    }

    public function for(object $message): callable
    {
        $class = $message::class;
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }
        if (isset($this->handlers[$class])) {
            return $this->resolved[$class] = $this->handlers[$class];
        }

        foreach (class_parents($message) + class_implements($message) as $type) {
            if (isset($this->handlers[$type])) {
                return $this->resolved[$class] = $this->handlers[$type];
            }
        }

        throw new HandlerNotFound(sprintf('No handler is registered for "%s".', $class));
    }

    private static function validateMapping(mixed $type, mixed $handler): void
    {
        if (
            !is_string($type)
            || (!class_exists($type) && !interface_exists($type))
            || !is_callable($handler)
        ) {
            throw new \InvalidArgumentException('Handler mappings require loadable types and callables.');
        }
    }
}

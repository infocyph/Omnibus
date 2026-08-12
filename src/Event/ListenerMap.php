<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

final class ListenerMap implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable|ShouldQueue>> */
    private array $resolved = [];

    /** @param array<class-string, list<callable|ShouldQueue>> $listeners */
    public function __construct(private array $listeners = [])
    {
        foreach ($listeners as $type => $registered) {
            self::validateMapping($type, $registered);
        }
    }

    /** @return iterable<callable|ShouldQueue> */
    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }

        $listeners = $this->listeners[$class] ?? [];
        foreach (class_parents($event) as $type) {
            foreach ($this->listeners[$type] ?? [] as $listener) {
                $listeners[] = $listener;
            }
        }
        $interfaces = class_implements($event);
        sort($interfaces);
        foreach ($interfaces as $type) {
            foreach ($this->listeners[$type] ?? [] as $listener) {
                $listeners[] = $listener;
            }
        }

        return $this->resolved[$class] = $listeners;
    }

    private static function validateMapping(mixed $type, mixed $listeners): void
    {
        if (!is_string($type) || (!class_exists($type) && !interface_exists($type))) {
            throw new \InvalidArgumentException('Listener mappings require loadable event types.');
        }
        if (!is_array($listeners)) {
            throw new \InvalidArgumentException('Listener mappings must contain listener lists.');
        }
        foreach ($listeners as $listener) {
            if (!is_callable($listener) && !$listener instanceof ShouldQueue) {
                throw new \InvalidArgumentException(
                    'Listener mappings must contain callables or ShouldQueue markers.',
                );
            }
        }
    }
}

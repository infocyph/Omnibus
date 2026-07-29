<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

final class ListenerMap implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable>> */
    private array $resolved = [];

    /** @param array<class-string, list<callable>> $listeners */
    public function __construct(private array $listeners = []) {}

    /** @return iterable<callable> */
    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }

        $listeners = $this->listeners[$class] ?? [];
        foreach (class_parents($event) + class_implements($event) as $type) {
            foreach ($this->listeners[$type] ?? [] as $listener) {
                $listeners[] = $listener;
            }
        }

        return $this->resolved[$class] = $listeners;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

use Infocyph\Omnibus\MessageBus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $listeners,
        private ?MessageBus $bus = null,
    ) {}

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if (!is_callable($listener)) {
                throw new \UnexpectedValueException('Listener providers must return callable listeners.');
            }

            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $queued = $this->queuedListener($listener);
            if ($queued !== null) {
                if ($this->bus === null) {
                    throw new QueuedListenerNotConfigured(
                        sprintf('Queued listener "%s" requires a configured message bus.', $queued::class),
                    );
                }
                $this->bus->dispatch(new QueuedListener($queued::class, $event));

                continue;
            }

            $listener($event);
        }

        return $event;
    }

    private function queuedListener(callable $listener): ?ShouldQueue
    {
        if ($listener instanceof ShouldQueue) {
            return $listener;
        }

        if (is_array($listener) && $listener[0] instanceof ShouldQueue) {
            return $listener[0];
        }

        return null;
    }
}

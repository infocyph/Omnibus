<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Event;

final readonly class QueuedListenerHandler
{
    public function __construct(private QueuedListenerResolver $listeners) {}

    public function __invoke(QueuedListener $message): mixed
    {
        $listener = $this->listeners->resolve($message->listener);

        return $listener($message->event);
    }
}

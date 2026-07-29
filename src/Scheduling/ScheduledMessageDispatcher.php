<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Scheduling;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\MessageBus;

final readonly class ScheduledMessageDispatcher
{
    public function __construct(
        private MessageFactoryMap $messages,
        private MessageBus $bus,
    ) {}

    public function dispatch(string $key): Envelope
    {
        return $this->bus->dispatch($this->messages->create($key));
    }
}

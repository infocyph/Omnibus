<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Dispatch;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\MessageBus;

final readonly class AfterResponseDispatcher
{
    public function __construct(
        private MessageBus $bus,
        private AfterResponseRuntime $runtime,
    ) {}

    public function dispatch(object $message): void
    {
        $envelope = Envelope::wrap($message);
        $this->runtime->defer(function () use ($envelope): void {
            $this->bus->dispatch($envelope);
        });
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class BroadcastHandler
{
    public function __construct(private Broadcaster $broadcaster) {}

    public function __invoke(Broadcast $message): void
    {
        $this->broadcaster->broadcast($message);
    }
}

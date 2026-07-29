<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class CallbackBroadcaster implements Broadcaster
{
    /** @var \Closure(Broadcast):void */
    private \Closure $publisher;

    /** @param callable(Broadcast):void $publisher */
    public function __construct(callable $publisher)
    {
        $this->publisher = \Closure::fromCallable($publisher);
    }

    public function broadcast(Broadcast $broadcast): void
    {
        ($this->publisher)($broadcast);
    }
}

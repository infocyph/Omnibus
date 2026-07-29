<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Omnibus\MessageBus;

final readonly class AfterCommitDispatcher
{
    public function __construct(
        private Connection $connection,
        private MessageBus $bus,
    ) {}

    public function dispatch(object $message): void
    {
        $this->connection->afterCommit(function () use ($message): void {
            $this->bus->dispatch($message);
        });
    }
}

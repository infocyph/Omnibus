<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Runwire\Supervisor\WorkerContext;

final readonly class RunwireWorkerLifecycle implements WorkerLifecycle
{
    public function __construct(private WorkerContext $context) {}

    public function heartbeat(): void {}

    public function stopRequested(): bool
    {
        return $this->context->stopping();
    }
}

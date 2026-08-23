<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

interface WorkerLifecycle
{
    public function heartbeat(): void;

    public function stopRequested(): bool;
}

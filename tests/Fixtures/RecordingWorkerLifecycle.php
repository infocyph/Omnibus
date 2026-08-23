<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Consumer\WorkerLifecycle;

final class RecordingWorkerLifecycle implements WorkerLifecycle
{
    public int $heartbeats = 0;

    public int $stopChecks = 0;

    private readonly ?\Closure $onHeartbeat;

    private readonly ?\Closure $onStopRequested;

    public function __construct(
        ?callable $onHeartbeat = null,
        ?callable $onStopRequested = null,
    ) {
        $this->onHeartbeat = $onHeartbeat === null ? null : $onHeartbeat(...);
        $this->onStopRequested = $onStopRequested === null ? null : $onStopRequested(...);
    }

    public function heartbeat(): void
    {
        $this->heartbeats++;
        ($this->onHeartbeat ?? static function (): void {})($this->heartbeats, $this);
    }

    public function stopRequested(): bool
    {
        $this->stopChecks++;

        return (bool) ($this->onStopRequested ?? static fn(): bool => false)($this->stopChecks, $this);
    }
}

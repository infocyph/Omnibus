<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Telemetry;

use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureRetryClaim;
use Infocyph\Omnibus\Failure\FailureStore;

final readonly class ObservedFailureStore implements FailureStore
{
    public function __construct(
        private FailureStore $inner,
        private TelemetrySink $telemetry,
    ) {}

    public function add(FailedMessage $failure): void
    {
        $this->inner->add($failure);

        try {
            $this->telemetry->record('queue.failed', 1, [
                'queue' => $failure->queue,
                'failure' => $failure->failureClass,
            ]);
        } catch (\Throwable) {
            // Failure persistence must not be reported as failed by telemetry.
        }
    }

    public function all(int $limit = 100): array
    {
        return $this->inner->all($limit);
    }

    public function claimRetry(string $id, float $leaseSeconds = 30.0): FailureRetryClaim
    {
        return $this->inner->claimRetry($id, $leaseSeconds);
    }

    public function clear(): int
    {
        return $this->inner->clear();
    }

    public function find(string $id): ?FailedMessage
    {
        return $this->inner->find($id);
    }

    public function markRetrySent(FailureRetryClaim $claim): bool
    {
        return $this->inner->markRetrySent($claim);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->inner->prune($before);
    }

    public function releaseRetry(FailureRetryClaim $claim): bool
    {
        return $this->inner->releaseRetry($claim);
    }

    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }

    public function removeRetried(FailureRetryClaim $claim): bool
    {
        return $this->inner->removeRetried($claim);
    }
}

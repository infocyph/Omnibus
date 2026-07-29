<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Telemetry;

use Infocyph\Omnibus\Failure\FailedMessage;
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
        $this->telemetry->record('queue.failed', 1, [
            'queue' => $failure->queue,
            'failure' => $failure->failureClass,
        ]);
    }

    public function all(int $limit = 100): array
    {
        return $this->inner->all($limit);
    }

    public function clear(): int
    {
        return $this->inner->clear();
    }

    public function find(string $id): ?FailedMessage
    {
        return $this->inner->find($id);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->inner->prune($before);
    }

    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }
}

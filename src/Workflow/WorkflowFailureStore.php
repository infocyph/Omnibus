<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureStore;

final readonly class WorkflowFailureStore implements FailureStore
{
    public function __construct(
        private FailureStore $inner,
        private WorkflowCoordinator $workflows,
    ) {}

    public function add(FailedMessage $failure): void
    {
        $this->inner->add($failure);
        if ($failure->envelope !== null) {
            $this->workflows->fail($failure->envelope);
        }
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

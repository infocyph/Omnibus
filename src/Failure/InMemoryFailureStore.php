<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

final class InMemoryFailureStore implements FailureStore
{
    /** @var array<string, FailedMessage> */
    private array $failures = [];

    public function add(FailedMessage $failure): void
    {
        $this->failures[$failure->id] = $failure;
    }

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Failure list limit must be between 1 and 1000.');
        }

        return array_slice(array_values($this->failures), 0, $limit);
    }

    public function clear(): int
    {
        $count = count($this->failures);
        $this->failures = [];

        return $count;
    }

    public function find(string $id): ?FailedMessage
    {
        return $this->failures[$id] ?? null;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $removed = 0;
        foreach ($this->failures as $id => $failure) {
            if ($failure->failedAt >= $before) {
                continue;
            }
            unset($this->failures[$id]);
            $removed++;
        }

        return $removed;
    }

    public function remove(string $id): bool
    {
        if (!isset($this->failures[$id])) {
            return false;
        }
        unset($this->failures[$id]);

        return true;
    }
}

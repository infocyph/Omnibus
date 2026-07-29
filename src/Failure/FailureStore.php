<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

interface FailureStore
{
    public function add(FailedMessage $failure): void;

    /** @return list<FailedMessage> */
    public function all(int $limit = 100): array;

    public function clear(): int;

    public function find(string $id): ?FailedMessage;

    public function prune(\DateTimeImmutable $before): int;

    public function remove(string $id): bool;
}

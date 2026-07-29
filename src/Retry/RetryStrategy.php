<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Retry;

interface RetryStrategy
{
    public function delaySeconds(int $attempt): float;

    public function shouldRetry(\Throwable $failure, int $attempt): bool;
}

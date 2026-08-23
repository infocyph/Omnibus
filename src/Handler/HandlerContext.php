<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Handler;

final readonly class HandlerContext
{
    public function __construct(
        public string $queue,
        public int $attempt = 1,
        public bool $asynchronous = false,
    ) {
        if ($queue === '') {
            throw new \InvalidArgumentException('Handler queue must not be empty.');
        }
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Handler attempt must be at least 1.');
        }
    }
}

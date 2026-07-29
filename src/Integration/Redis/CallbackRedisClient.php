<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Redis;

final readonly class CallbackRedisClient implements RedisClient
{
    /** @var \Closure(string, string...):mixed */
    private \Closure $executor;

    /** @param callable(string, string...):mixed $executor */
    public function __construct(callable $executor)
    {
        $this->executor = \Closure::fromCallable($executor);
    }

    public function execute(string $command, string ...$arguments): mixed
    {
        return ($this->executor)($command, ...$arguments);
    }
}

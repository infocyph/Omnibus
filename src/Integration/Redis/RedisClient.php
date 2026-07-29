<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Redis;

interface RedisClient
{
    public function execute(string $command, string ...$arguments): mixed;
}

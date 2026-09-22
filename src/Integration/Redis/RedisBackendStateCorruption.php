<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Redis;

final class RedisBackendStateCorruption extends \RuntimeException
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $field,
    ) {
        parent::__construct(sprintf(
            'Redis message "%s" is missing or has inconsistent structural field "%s".',
            $messageId,
            $field,
        ));
    }
}

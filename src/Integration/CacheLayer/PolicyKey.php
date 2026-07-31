<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

final class PolicyKey
{
    public static function assert(string $key): void
    {
        if (
            $key === ''
            || strlen($key) > 512
            || preg_match('/[\x00-\x1F\x7F]/D', $key) === 1
        ) {
            throw new \UnexpectedValueException(
                'Policy keys must contain between 1 and 512 bytes without control characters.',
            );
        }
    }
}

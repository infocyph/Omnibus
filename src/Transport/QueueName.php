<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

final class QueueName
{
    public const int MAXIMUM_BYTES = 191;

    public static function assert(string $queue): void
    {
        if (
            $queue === ''
            || strlen($queue) > self::MAXIMUM_BYTES
            || preg_match('/[\x00-\x1F\x7F]/D', $queue) === 1
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Queue names must contain between 1 and %d bytes without control characters.',
                self::MAXIMUM_BYTES,
            ));
        }
    }
}

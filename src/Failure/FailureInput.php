<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

final class FailureInput
{
    public const int MAX_REASON_BYTES = 16_384;

    public static function id(string $candidate, string $queue, string $receipt): string
    {
        if (
            $candidate !== ''
            && strlen($candidate) <= 191
            && preg_match('/[\x00-\x1F\x7F]/D', $candidate) !== 1
        ) {
            return $candidate;
        }

        return 'receipt-' . hash('sha256', $queue . "\0" . $receipt);
    }

    public static function reason(string $reason): string
    {
        return strlen($reason) > self::MAX_REASON_BYTES
            ? substr($reason, 0, self::MAX_REASON_BYTES)
            : $reason;
    }
}

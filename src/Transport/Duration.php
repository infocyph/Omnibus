<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

final class Duration
{
    private function __construct() {}

    public static function microseconds(float $seconds, int $base = 0): int
    {
        if (!is_finite($seconds) || $seconds < 0.0 || $base < 0) {
            throw new \InvalidArgumentException('Duration and timestamp base must be finite and non-negative.');
        }

        $maximum = (PHP_INT_MAX - $base) / 1_000_000;
        if ($seconds > $maximum) {
            throw new \InvalidArgumentException('Duration exceeds the supported timestamp range.');
        }

        return (int) round($seconds * 1_000_000);
    }
}

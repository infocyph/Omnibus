<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Internal;

final class Time
{
    private const int MICROS_PER_SECOND = 1_000_000;

    private function __construct() {}

    public static function add(int $timestamp, float $seconds): int
    {
        return $timestamp + self::duration($seconds, $timestamp);
    }

    public static function duration(float $seconds, int $base = 0): int
    {
        if (!is_finite($seconds) || $seconds < 0.0 || $base < 0) {
            throw new \InvalidArgumentException(
                'Duration and timestamp base must be finite and non-negative.',
            );
        }

        $maximum = (PHP_INT_MAX - $base) / self::MICROS_PER_SECOND;
        if ($seconds > $maximum) {
            throw new \InvalidArgumentException('Duration exceeds the supported timestamp range.');
        }

        return (int) round($seconds * self::MICROS_PER_SECOND);
    }

    public static function fromDate(\DateTimeImmutable $date): int
    {
        $seconds = (int) $date->format('U');
        if ($seconds < 0 || $seconds > intdiv(PHP_INT_MAX, self::MICROS_PER_SECOND)) {
            throw new \InvalidArgumentException('Date is outside the supported timestamp range.');
        }

        return $seconds * self::MICROS_PER_SECOND + (int) $date->format('u');
    }

    public static function toDate(int $microseconds): \DateTimeImmutable
    {
        if ($microseconds < 0) {
            throw new \InvalidArgumentException('Timestamp must be non-negative.');
        }

        $seconds = intdiv($microseconds, self::MICROS_PER_SECOND);
        $remainder = $microseconds % self::MICROS_PER_SECOND;
        $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $remainder));
        if (!$date instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('Timestamp cannot be represented as a date.');
        }

        return $date;
    }
}

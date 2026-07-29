<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;

final class CoreStampCodecs
{
    /** @return list<StampCodec> */
    public static function all(): array
    {
        return [
            new CallbackStampCodec(
                'message_id',
                MessageIdStamp::class,
                static fn(MessageIdStamp $stamp): array => ['id' => $stamp->id],
                static fn(array $data): MessageIdStamp => new MessageIdStamp(
                    self::string($data, 'id'),
                ),
            ),
            new CallbackStampCodec(
                'route',
                RouteStamp::class,
                static fn(RouteStamp $stamp): array => [
                    'transport' => $stamp->transport,
                    'queue' => $stamp->queue,
                ],
                static fn(array $data): RouteStamp => new RouteStamp(
                    self::string($data, 'transport'),
                    self::string($data, 'queue'),
                ),
            ),
            new CallbackStampCodec(
                'delay',
                DelayStamp::class,
                static fn(DelayStamp $stamp): array => ['seconds' => $stamp->seconds],
                static fn(array $data): DelayStamp => new DelayStamp(
                    self::float($data, 'seconds'),
                ),
            ),
            new CallbackStampCodec(
                'attempt',
                AttemptStamp::class,
                static fn(AttemptStamp $stamp): array => ['attempt' => $stamp->attempt],
                static fn(array $data): AttemptStamp => new AttemptStamp(
                    self::int($data, 'attempt'),
                ),
            ),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Stamp field "%s" must be numeric.', $key));
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Stamp field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Stamp field "%s" must be a string.', $key));
        }

        return $value;
    }
}

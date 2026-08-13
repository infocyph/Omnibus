<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class Broadcast
{
    private const int DEFAULT_MAXIMUM_PAYLOAD_BYTES = 262_144;

    /**
     * @param list<Channel> $channels
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $event,
        public array $channels,
        public array $payload,
        int $maximumPayloadBytes = self::DEFAULT_MAXIMUM_PAYLOAD_BYTES,
    ) {
        if (
            $event === ''
            || strlen($event) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $event) === 1
            || $channels === []
            || count($channels) > 1_000
            || $maximumPayloadBytes < 1
        ) {
            throw new \InvalidArgumentException('A broadcast requires an event and at least one channel.');
        }
        foreach ($channels as $channel) {
            self::validateChannel($channel);
        }

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                64,
            );
        } catch (\JsonException $failure) {
            throw new \InvalidArgumentException(
                'Broadcast payload must be bounded JSON-compatible data.',
                previous: $failure,
            );
        }
        if (strlen($encoded) > $maximumPayloadBytes) {
            throw new \LengthException('Broadcast payload exceeds the configured byte limit.');
        }
        self::validatePayload($payload);
    }

    private static function validateChannel(mixed $channel): void
    {
        if (!$channel instanceof Channel) {
            throw new \InvalidArgumentException('Broadcast channels must be Channel values.');
        }
    }

    private static function validatePayload(mixed $value): void
    {
        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_string($value)
            || (is_float($value) && is_finite($value))
        ) {
            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Broadcast payload must contain JSON-compatible values.');
        }

        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            if (!$list && !is_string($key)) {
                throw new \InvalidArgumentException('Broadcast payload maps must use string keys.');
            }
            self::validatePayload($item);
        }
    }
}

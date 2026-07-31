<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class Broadcast
{
    /**
     * @param list<Channel> $channels
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $event,
        public array $channels,
        public array $payload,
    ) {
        if (
            $event === ''
            || strlen($event) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $event) === 1
            || $channels === []
            || count($channels) > 1_000
        ) {
            throw new \InvalidArgumentException('A broadcast requires an event and at least one channel.');
        }
        foreach ($channels as $channel) {
            self::validateChannel($channel);
        }
        foreach ($payload as $key => $_value) {
            self::validatePayloadKey($key);
        }
    }

    private static function validateChannel(mixed $channel): void
    {
        if (!$channel instanceof Channel) {
            throw new \InvalidArgumentException('Broadcast channels must be Channel values.');
        }
    }

    private static function validatePayloadKey(mixed $key): void
    {
        if (!is_string($key)) {
            throw new \InvalidArgumentException('Broadcast payload keys must be strings.');
        }
    }
}

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
        if ($event === '' || strlen($event) > 200 || $channels === []) {
            throw new \InvalidArgumentException('A broadcast requires an event and at least one channel.');
        }
    }
}

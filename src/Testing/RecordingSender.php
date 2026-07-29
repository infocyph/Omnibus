<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Testing;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Sender;

final class RecordingSender implements Sender
{
    /** @var list<array{envelope: Envelope, queue: string}> */
    private array $sent = [];

    public function clear(): void
    {
        $this->sent = [];
    }

    public function count(string|object|null $message = null): int
    {
        if ($message === null) {
            return count($this->sent);
        }

        $type = is_object($message) ? $message::class : $message;
        $count = 0;
        foreach ($this->sent as $entry) {
            if ($entry['envelope']->message instanceof $type) {
                $count++;
            }
        }

        return $count;
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $this->sent[] = ['envelope' => $envelope, 'queue' => $queue];

        return $envelope;
    }

    /** @return list<array{envelope: Envelope, queue: string}> */
    public function sent(): array
    {
        return $this->sent;
    }
}

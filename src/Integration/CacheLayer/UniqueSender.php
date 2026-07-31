<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Transport\Sender;

final readonly class UniqueSender implements Sender
{
    /** @var \Closure(Envelope):string */
    private \Closure $key;

    /** @param callable(Envelope):string $key */
    public function __construct(
        private Sender $sender,
        private DetachedLeaseProvider $locks,
        callable $key,
        private float $leaseSeconds = 300.0,
        private float $waitSeconds = 0.0,
    ) {
        if (
            !is_finite($leaseSeconds)
            || $leaseSeconds <= 0.0
            || !is_finite($waitSeconds)
            || $waitSeconds < 0.0
        ) {
            throw new \InvalidArgumentException('Unique-message wait and lease values are invalid.');
        }
        $this->key = \Closure::fromCallable($key);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $key = ($this->key)($envelope);
        PolicyKey::assert($key);
        $handle = $this->locks->acquire($key, $this->waitSeconds, $this->leaseSeconds);
        if ($handle === null) {
            throw new DuplicateMessage(sprintf('Unique message "%s" is already active.', $key));
        }

        try {
            return $this->sender->send(
                $envelope->with(new UniqueStamp($handle->key, $handle->token, $this->leaseSeconds)),
                $queue,
            );
        } catch (\Throwable $failure) {
            $this->locks->release($handle);

            throw $failure;
        }
    }
}

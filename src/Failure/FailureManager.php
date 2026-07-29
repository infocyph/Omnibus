<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\Sender;

final readonly class FailureManager
{
    public function __construct(private FailureStore $failures) {}

    public function flush(): int
    {
        return $this->failures->clear();
    }

    public function forget(string $id): bool
    {
        return $this->failures->remove($id);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->failures->prune($before);
    }

    public function retry(string $id, Sender $sender, ?string $queue = null): Envelope
    {
        $failure = $this->failures->find($id)
            ?? throw new FailureNotFound(sprintf('Failed message "%s" was not found.', $id));
        if (!$failure->envelope instanceof Envelope) {
            throw new UndecodableFailure(sprintf(
                'Failed message "%s" cannot be retried until its payload codec is available.',
                $id,
            ));
        }

        $sent = $sender->send($failure->envelope, $queue ?? $failure->queue);
        $this->failures->remove($id);

        return $sent;
    }
}

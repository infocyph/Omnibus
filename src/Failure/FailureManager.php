<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\BatchStamp;
use Infocyph\Omnibus\Envelope\ChainStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\EnqueuedAtStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;
use Infocyph\Omnibus\Envelope\UniqueStamp;
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

        if (
            $failure->envelope->last(ChainStamp::class) instanceof ChainStamp
            || $failure->envelope->last(BatchStamp::class) instanceof BatchStamp
        ) {
            throw new WorkflowFailureRequiresRecovery(sprintf(
                'Failed workflow message "%s" requires workflow-specific recovery.',
                $id,
            ));
        }

        $replay = $failure->envelope->without(
            AttemptStamp::class,
            EnqueuedAtStamp::class,
            UniqueStamp::class,
            RouteStamp::class,
            DelayStamp::class,
            HandledStamp::class,
        );
        $sent = $sender->send($replay, $queue ?? $failure->queue);
        if (!$this->failures->remove($id)) {
            throw new FailureRemovalAfterRetryFailed($id, $sent);
        }

        return $sent;
    }
}

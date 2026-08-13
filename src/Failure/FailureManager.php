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

    public function retry(
        string $id,
        Sender $sender,
        ?string $queue = null,
        float $claimLeaseSeconds = 30.0,
    ): Envelope {
        $claim = $this->failures->claimRetry($id, $claimLeaseSeconds);

        try {
            $replay = self::replay($claim->failure);
        } catch (\Throwable $failure) {
            $this->releaseQuietly($claim);

            throw $failure;
        }

        try {
            $sent = $sender->send($replay, $queue ?? $claim->failure->queue);
        } catch (\Throwable $failure) {
            $this->releaseQuietly($claim);

            throw $failure;
        }

        try {
            $removed = $this->failures->markRetrySent($claim)
                && $this->failures->removeRetried($claim);
        } catch (\Throwable $failure) {
            throw new FailureRemovalAfterRetryFailed($id, $sent, $failure);
        }
        if (!$removed) {
            throw new FailureRemovalAfterRetryFailed($id, $sent);
        }

        return $sent;
    }

    private static function replay(FailedMessage $failure): Envelope
    {
        if (!$failure->envelope instanceof Envelope) {
            throw new UndecodableFailure(sprintf(
                'Failed message "%s" cannot be retried until its payload codec is available.',
                $failure->id,
            ));
        }
        if (
            $failure->envelope->last(ChainStamp::class) instanceof ChainStamp
            || $failure->envelope->last(BatchStamp::class) instanceof BatchStamp
        ) {
            throw new WorkflowFailureRequiresRecovery(sprintf(
                'Failed workflow message "%s" requires workflow-specific recovery.',
                $failure->id,
            ));
        }

        return $failure->envelope->without(
            AttemptStamp::class,
            EnqueuedAtStamp::class,
            UniqueStamp::class,
            RouteStamp::class,
            DelayStamp::class,
            HandledStamp::class,
        );
    }

    private function releaseQuietly(FailureRetryClaim $claim): void
    {
        try {
            $this->failures->releaseRetry($claim);
        } catch (\Throwable) {
            // Retry validation/send failure remains the primary failure.
        }
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\UniqueStamp;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\Omnibus\Workflow\AtomicWorkflowTransport;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Infocyph\Omnibus\Workflow\WorkflowTransition;

final readonly class UniqueTransport implements AtomicWorkflowTransport, Transport
{
    /** @var (\Closure(\Throwable, Reservation):void)|null */
    private ?\Closure $cleanupFailure;

    /** @param callable(\Throwable, Reservation):void|null $cleanupFailure */
    public function __construct(
        private Transport $inner,
        private DetachedLeaseProvider $locks,
        ?callable $cleanupFailure = null,
    ) {
        $this->cleanupFailure = $cleanupFailure === null
            ? null
            : \Closure::fromCallable($cleanupFailure);
    }

    public function acknowledge(Reservation $reservation): void
    {
        $this->inner->acknowledge($reservation);
        $this->releaseLease($reservation);
    }

    public function acknowledgeWorkflow(
        Reservation $reservation,
        WorkflowStore $store,
    ): WorkflowTransition {
        if (!$this->inner instanceof AtomicWorkflowTransport) {
            throw new \LogicException('The decorated transport does not support atomic workflow settlement.');
        }

        $transition = $this->inner->acknowledgeWorkflow($reservation, $store);
        $this->releaseLease($reservation);

        return $transition;
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        return $this->inner->receive($queue, $limit, $visibilitySeconds);
    }

    public function reject(Reservation $reservation): void
    {
        $this->inner->reject($reservation);
        $this->releaseLease($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Release delay must be a finite non-negative number.');
        }
        $handle = $this->handle($reservation);
        $requiredLease = $handle instanceof LockHandle
            ? $handle->leaseSeconds + $delaySeconds
            : 0.0;
        Time::duration($requiredLease);
        if (
            $handle instanceof LockHandle
            && (
                !is_finite($requiredLease)
                || !$this->locks->refresh($handle, $requiredLease)
            )
        ) {
            throw new LeaseLost(sprintf('Unique-message lease "%s" was lost.', $handle->key));
        }
        $this->inner->release($reservation, $delaySeconds);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        return $this->inner->send($envelope, $queue);
    }

    public function size(string $queue): int
    {
        return $this->inner->size($queue);
    }

    public function supportsWorkflowStore(WorkflowStore $store): bool
    {
        return $this->inner instanceof AtomicWorkflowTransport
            && $this->inner->supportsWorkflowStore($store);
    }

    private function handle(Reservation $reservation): ?LockHandle
    {
        if ($reservation->decodingFailure() !== null) {
            return null;
        }
        $stamp = $reservation->envelope()->last(UniqueStamp::class);

        return $stamp instanceof UniqueStamp
            ? new LockHandle($stamp->key, $stamp->token, leaseSeconds: $stamp->leaseSeconds)
            : null;
    }

    private function releaseLease(Reservation $reservation): void
    {
        try {
            $this->locks->release($this->handle($reservation));
        } catch (\Throwable $failure) {
            if (!$this->cleanupFailure instanceof \Closure) {
                return;
            }

            try {
                ($this->cleanupFailure)($failure, $reservation);
            } catch (\Throwable) {
                // Queue settlement is already durable; lease TTL owns cleanup.
            }
        }
    }
}

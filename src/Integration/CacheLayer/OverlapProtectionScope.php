<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class OverlapProtectionScope implements ExecutionScope
{
    /** @var \Closure(Envelope):string */
    private \Closure $key;

    /** @param callable(Envelope):string $key */
    public function __construct(
        private ExecutionScope $inner,
        private LockProviderInterface $locks,
        callable $key,
        private float $leaseSeconds = 60.0,
        private float $waitSeconds = 0.0,
    ) {
        if (
            !is_finite($leaseSeconds)
            || $leaseSeconds <= 0.0
            || !is_finite($waitSeconds)
            || $waitSeconds < 0.0
        ) {
            throw new \InvalidArgumentException('Overlap wait and lease values are invalid.');
        }
        $this->key = $key(...);
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $logicalKey = ($this->key)($envelope);
        $key = PolicyKey::storage('overlap', $logicalKey);
        $handle = $this->locks->acquire($key, $this->waitSeconds, $this->leaseSeconds);
        if ($handle === null) {
            throw new MessageOverlap(sprintf('Message overlap lock "%s" is active.', $logicalKey));
        }

        try {
            $result = $this->inner->run($envelope, $handler);
            if (!$this->locks->refresh($handle, $this->leaseSeconds)) {
                throw new OverlapLeaseLostAfterExecution(sprintf(
                    'Message overlap lease "%s" was lost after execution.',
                    $logicalKey,
                ));
            }
        } catch (\Throwable $failure) {
            try {
                $this->locks->release($handle);
            } catch (\Throwable) {
                // Handler/lease failure remains primary.
            }

            throw $failure;
        }

        try {
            $this->locks->release($handle);
        } catch (\Throwable $failure) {
            throw new CoordinationCleanupFailedAfterExecution(
                sprintf('Message overlap lease "%s" could not be released after execution.', $logicalKey),
                previous: $failure,
            );
        }

        return $result;
    }
}

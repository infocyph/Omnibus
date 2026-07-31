<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\CacheLayer;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Psr\Clock\ClockInterface;

final readonly class FixedWindowRateLimitScope implements ExecutionScope
{
    /** @var \Closure(Envelope):string */
    private \Closure $key;

    /** @param callable(Envelope):string $key */
    public function __construct(
        private ExecutionScope $inner,
        private AtomicCounterStoreInterface $counters,
        private ClockInterface $clock,
        callable $key,
        private int $maximum,
        private int $windowSeconds,
    ) {
        if ($maximum < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate-limit maximum and window must be positive.');
        }
        $this->key = \Closure::fromCallable($key);
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $key = ($this->key)($envelope);
        PolicyKey::assert($key);
        $timestamp = (int) $this->clock->now()->format('U');
        $bucket = intdiv($timestamp, $this->windowSeconds);
        $value = $this->counters->increment(
            sprintf('omnibus:rate:%s:%d', $key, $bucket),
            ttlSeconds: $this->windowSeconds + 1,
        );
        if ($value->value > $this->maximum) {
            throw new RateLimitExceeded(sprintf('Rate limit "%s" is exhausted.', $key));
        }

        return $this->inner->run($envelope, $handler);
    }
}

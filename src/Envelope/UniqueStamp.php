<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class UniqueStamp implements Stamp
{
    public function __construct(
        public string $key,
        public string $token,
        public float $leaseSeconds,
    ) {
        if ($key === '' || $token === '' || !is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('A unique stamp requires a key, token, and positive lease.');
        }
    }
}

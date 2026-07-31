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
        if (
            $key === ''
            || strlen($key) > 512
            || preg_match('/[\x00-\x1F\x7F]/D', $key) === 1
            || $token === ''
            || strlen($token) > 512
            || preg_match('/[\x00-\x1F\x7F]/D', $token) === 1
            || !is_finite($leaseSeconds)
            || $leaseSeconds <= 0.0
        ) {
            throw new \InvalidArgumentException('A unique stamp requires a key, token, and positive lease.');
        }
    }
}

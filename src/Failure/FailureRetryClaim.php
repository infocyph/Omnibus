<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

final readonly class FailureRetryClaim
{
    public function __construct(
        public FailedMessage $failure,
        public string $token,
        public int $expiresAt,
    ) {
        if ($token === '' || $expiresAt < 0) {
            throw new \InvalidArgumentException('A failure retry claim requires a token and expiry.');
        }
    }
}

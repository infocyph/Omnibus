<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

final readonly class DecodeFailure
{
    public string $payload;

    public bool $truncated;

    public function __construct(
        string $payload,
        public string $failureClass,
        public string $reason,
        int $maximumPayloadBytes = 262_144,
    ) {
        if ($failureClass === '' || $maximumPayloadBytes < 1) {
            throw new \InvalidArgumentException('A decode failure requires a class and positive payload limit.');
        }

        $this->truncated = strlen($payload) > $maximumPayloadBytes;
        $this->payload = $this->truncated
            ? substr($payload, 0, $maximumPayloadBytes)
            : $payload;
    }

    public static function fromThrowable(
        string $payload,
        \Throwable $failure,
        int $maximumPayloadBytes = 262_144,
    ): self {
        return new self(
            $payload,
            $failure::class,
            $failure->getMessage(),
            $maximumPayloadBytes,
        );
    }
}

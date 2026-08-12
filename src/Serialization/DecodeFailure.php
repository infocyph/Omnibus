<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

final readonly class DecodeFailure
{
    private const int MAX_REASON_BYTES = 16_384;

    public string $payload;

    public string $reason;

    public bool $truncated;

    public function __construct(
        string $payload,
        public string $failureClass,
        string $reason,
        int $maximumPayloadBytes = 262_144,
    ) {
        if ($failureClass === '' || $maximumPayloadBytes < 1) {
            throw new \InvalidArgumentException('A decode failure requires a class and positive payload limit.');
        }

        $this->truncated = strlen($payload) > $maximumPayloadBytes;
        $this->payload = $this->truncated
            ? substr($payload, 0, $maximumPayloadBytes)
            : $payload;
        $this->reason = strlen($reason) > self::MAX_REASON_BYTES
            ? substr($reason, 0, self::MAX_REASON_BYTES)
            : $reason;
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

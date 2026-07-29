<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class CallbackEnvelopeSerializer implements EnvelopeSerializer
{
    /** @var \Closure(string):Envelope */
    private \Closure $decoder;

    /** @var \Closure(Envelope):string */
    private \Closure $encoder;

    /**
     * @param callable(string):Envelope $decoder
     * @param callable(Envelope):string $encoder
     */
    public function __construct(
        callable $decoder,
        callable $encoder,
        private int $maximumBytes = 262_144,
    ) {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Maximum payload bytes must be positive.');
        }
        $this->decoder = \Closure::fromCallable($decoder);
        $this->encoder = \Closure::fromCallable($encoder);
    }

    public function decode(string $payload): Envelope
    {
        $this->assertSize($payload);

        return ($this->decoder)($payload);
    }

    public function encode(Envelope $envelope): string
    {
        $payload = ($this->encoder)($envelope);
        $this->assertSize($payload);

        return $payload;
    }

    private function assertSize(string $payload): void
    {
        if ($payload === '' || strlen($payload) > $this->maximumBytes) {
            throw new \LengthException('Envelope payload is empty or exceeds the configured limit.');
        }
    }
}

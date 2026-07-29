<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Stamp;

/**
 * @template T of Stamp
 */
final readonly class CallbackStampCodec implements StampCodec
{
    private \Closure $decoder;

    private \Closure $encoder;

    /**
     * @param class-string<T> $stampType
     * @param callable(T): array<string, bool|float|int|string|null> $encoder
     * @param callable(array<string, bool|float|int|string|null>): Stamp $decoder
     */
    public function __construct(
        private string $name,
        private string $stampType,
        callable $encoder,
        callable $decoder,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Stamp codec alias cannot be empty.');
        }
        $this->encoder = $encoder(...);
        $this->decoder = $decoder(...);
    }

    public function alias(): string
    {
        return $this->name;
    }

    public function decode(array $payload): Stamp
    {
        $stamp = ($this->decoder)($payload);
        if (!$stamp instanceof $this->stampType) {
            throw new \UnexpectedValueException(
                sprintf('Codec "%s" decoded an unexpected stamp type.', $this->name),
            );
        }

        return $stamp;
    }

    public function encode(Stamp $stamp): array
    {
        if (!$stamp instanceof $this->stampType) {
            throw new \InvalidArgumentException(
                sprintf('Codec "%s" cannot encode "%s".', $this->name, $stamp::class),
            );
        }

        return ($this->encoder)($stamp);
    }

    /** @return class-string<T> */
    public function type(): string
    {
        return $this->stampType;
    }
}

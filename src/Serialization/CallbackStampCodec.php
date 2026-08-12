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
        if (
            $name === ''
            || strlen($name) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $name) === 1
        ) {
            throw new \InvalidArgumentException(
                'Stamp codec aliases must contain between 1 and 200 bytes without control characters.',
            );
        }
        self::validateType($stampType);
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

        return self::validatePayload(($this->encoder)($stamp), $this->name);
    }

    /** @return class-string<T> */
    public function type(): string
    {
        return $this->stampType;
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array<string, bool|float|int|string|null>
     */
    private static function validatePayload(array $payload, string $name): array
    {
        foreach ($payload as $key => $value) {
            if (
                !is_string($key)
                || (!is_bool($value)
                    && !is_float($value)
                    && !is_int($value)
                    && !is_string($value)
                    && $value !== null)
                || (is_float($value) && !is_finite($value))
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Codec "%s" must encode a string-keyed scalar stamp map.',
                    $name,
                ));
            }
        }

        return $payload;
    }

    private static function validateType(string $stampType): void
    {
        if (
            !class_exists($stampType)
            || !is_a($stampType, Stamp::class, true)
            || !new \ReflectionClass($stampType)->isInstantiable()
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Stamp codec type "%s" must be a concrete class implementing %s.',
                $stampType,
                Stamp::class,
            ));
        }
    }
}

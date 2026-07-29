<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

/**
 * @template T of object
 */
final readonly class CallbackMessageCodec implements MessageCodec
{
    private \Closure $decoder;

    private \Closure $encoder;

    /**
     * @param class-string<T> $messageType
     * @param callable(T): array<string, mixed> $encoder
     * @param callable(array<string, mixed>): object $decoder
     */
    public function __construct(
        private string $name,
        private string $messageType,
        callable $encoder,
        callable $decoder,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Message codec alias cannot be empty.');
        }
        $this->encoder = $encoder(...);
        $this->decoder = $decoder(...);
    }

    public function alias(): string
    {
        return $this->name;
    }

    public function decode(array $payload): object
    {
        $message = ($this->decoder)($payload);
        if (!$message instanceof $this->messageType) {
            throw new \UnexpectedValueException(
                sprintf('Codec "%s" decoded an unexpected message type.', $this->name),
            );
        }

        return $message;
    }

    public function encode(object $message): array
    {
        if (!$message instanceof $this->messageType) {
            throw new \InvalidArgumentException(
                sprintf('Codec "%s" cannot encode "%s".', $this->name, $message::class),
            );
        }

        return ($this->encoder)($message);
    }

    /** @return class-string<T> */
    public function type(): string
    {
        return $this->messageType;
    }
}

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
        if (
            $name === ''
            || strlen($name) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $name) === 1
        ) {
            throw new \InvalidArgumentException(
                'Message codec aliases must contain between 1 and 200 bytes without control characters.',
            );
        }
        if (!class_exists($messageType) && !interface_exists($messageType)) {
            throw new \InvalidArgumentException(sprintf(
                'Message codec type "%s" is not a loadable class or interface.',
                $messageType,
            ));
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

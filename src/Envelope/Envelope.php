<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class Envelope
{
    /** @var list<Stamp> */
    private array $stamps;

    /** @param iterable<Stamp> $stamps */
    public function __construct(
        public object $message,
        iterable $stamps = [],
    ) {
        $resolved = [];
        foreach ($stamps as $stamp) {
            $resolved[] = $stamp;
        }
        $this->stamps = $resolved;
    }

    public static function wrap(object $message): self
    {
        return $message instanceof self ? $message : new self($message);
    }

    /**
     * @template T of Stamp
     * @param class-string<T> $type
     * @return list<T>
     */
    public function all(string $type): array
    {
        $matches = [];
        foreach ($this->stamps as $stamp) {
            if ($stamp instanceof $type) {
                $matches[] = $stamp;
            }
        }

        return $matches;
    }

    /**
     * @template T of Stamp
     * @param class-string<T> $type
     * @return T|null
     */
    public function last(string $type): ?Stamp
    {
        for ($index = count($this->stamps) - 1; $index >= 0; $index--) {
            $stamp = $this->stamps[$index];
            if ($stamp instanceof $type) {
                return $stamp;
            }
        }

        return null;
    }

    /** @return list<Stamp> */
    public function stamps(): array
    {
        return $this->stamps;
    }

    public function with(Stamp ...$stamps): self
    {
        if ($stamps === []) {
            return $this;
        }

        return new self($this->message, [...$this->stamps, ...$stamps]);
    }

    /** @param class-string<Stamp> ...$types */
    public function without(string ...$types): self
    {
        if ($types === []) {
            return $this;
        }

        foreach ($types as $type) {
            self::assertStampType($type);
        }

        $removed = false;
        $retained = [];
        foreach ($this->stamps as $stamp) {
            foreach ($types as $type) {
                if ($stamp instanceof $type) {
                    $removed = true;

                    continue 2;
                }
            }
            $retained[] = $stamp;
        }

        return $removed ? new self($this->message, $retained) : $this;
    }

    private static function assertStampType(string $type): void
    {
        if (!is_a($type, Stamp::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Envelope stamp type "%s" must implement %s.',
                $type,
                Stamp::class,
            ));
        }
    }
}

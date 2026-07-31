<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Stamp;

final readonly class StampCodecRegistry
{
    /** @var array<string, StampCodec> */
    private array $byAlias;

    /** @var array<class-string<Stamp>, StampCodec> */
    private array $byType;

    /** @param iterable<StampCodec> $codecs */
    public function __construct(iterable $codecs)
    {
        $byAlias = $byType = [];
        foreach ($codecs as $codec) {
            $alias = $codec->alias();
            $type = $codec->type();
            self::validateIdentity($alias, $type);
            if (isset($byAlias[$alias]) || isset($byType[$type])) {
                throw new \InvalidArgumentException('Stamp codec aliases and types must be unique.');
            }
            $byAlias[$alias] = $codec;
            $byType[$type] = $codec;
        }
        $this->byAlias = $byAlias;
        $this->byType = $byType;
    }

    public function forAlias(string $alias): StampCodec
    {
        return $this->byAlias[$alias]
            ?? throw new UnknownStampType(sprintf('Stamp alias "%s" is not allowed.', $alias));
    }

    public function forStamp(Stamp $stamp): StampCodec
    {
        return $this->byType[$stamp::class]
            ?? throw new UnknownStampType(
                sprintf('No stamp codec is registered for "%s".', $stamp::class),
            );
    }

    private static function validateIdentity(string $alias, string $type): void
    {
        if (
            $alias === ''
            || strlen($alias) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $alias) === 1
            || !is_a($type, Stamp::class, true)
        ) {
            throw new \InvalidArgumentException('Stamp codecs must expose a valid alias and stamp type.');
        }
    }
}

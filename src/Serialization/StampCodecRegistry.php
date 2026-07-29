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
            if (isset($byAlias[$codec->alias()]) || isset($byType[$codec->type()])) {
                throw new \InvalidArgumentException('Stamp codec aliases and types must be unique.');
            }
            $byAlias[$codec->alias()] = $codec;
            $byType[$codec->type()] = $codec;
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
}

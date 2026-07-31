<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

final readonly class MessageCodecRegistry
{
    /** @var array<string, MessageCodec> */
    private array $byAlias;

    /** @var array<class-string, MessageCodec> */
    private array $byType;

    /** @param iterable<MessageCodec> $codecs */
    public function __construct(iterable $codecs)
    {
        $byAlias = $byType = [];
        foreach ($codecs as $codec) {
            $alias = $codec->alias();
            $type = $codec->type();
            if (
                $alias === ''
                || strlen($alias) > 200
                || preg_match('/[\x00-\x1F\x7F]/D', $alias) === 1
                || (!class_exists($type) && !interface_exists($type))
            ) {
                throw new \InvalidArgumentException('Message codecs must expose a valid alias and type.');
            }
            if (isset($byAlias[$alias]) || isset($byType[$type])) {
                throw new \InvalidArgumentException('Message codec aliases and types must be unique.');
            }
            $byAlias[$alias] = $codec;
            $byType[$type] = $codec;
        }
        $this->byAlias = $byAlias;
        $this->byType = $byType;
    }

    public function forAlias(string $alias): MessageCodec
    {
        return $this->byAlias[$alias]
            ?? throw new UnknownMessageType(sprintf('Message alias "%s" is not allowed.', $alias));
    }

    public function forMessage(object $message): MessageCodec
    {
        return $this->byType[$message::class]
            ?? throw new UnknownMessageType(
                sprintf('No message codec is registered for "%s".', $message::class),
            );
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

final class TestSerializer
{
    public static function make(): JsonEnvelopeSerializer
    {
        return new JsonEnvelopeSerializer(
            new MessageCodecRegistry([
                new CallbackMessageCodec(
                    'test.command.v1',
                    TestCommand::class,
                    static fn(TestCommand $message): array => ['value' => $message->value],
                    static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
                ),
            ]),
            new StampCodecRegistry(CoreStampCodecs::all()),
        );
    }
}

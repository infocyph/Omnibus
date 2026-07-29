<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Serialization\UnknownMessageType;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;

function omnibusSerializer(): JsonEnvelopeSerializer
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

test('json serializer round trips only explicitly registered message types', function (): void {
    $serializer = omnibusSerializer();
    $payload = $serializer->encode(
        new Envelope(new TestCommand('safe'), [new MessageIdStamp('01ABC')]),
    );
    $decoded = $serializer->decode($payload);

    expect($decoded->message)->toEqual(new TestCommand('safe'))
        ->and($decoded->last(MessageIdStamp::class)?->id)->toBe('01ABC');
});

test('json serializer rejects unregistered external aliases', function (): void {
    $payload = json_encode([
        'version' => 1,
        'message' => ['type' => 'arbitrary.class', 'data' => []],
        'stamps' => [],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => omnibusSerializer()->decode($payload))
        ->toThrow(UnknownMessageType::class);
});

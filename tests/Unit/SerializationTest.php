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
use Infocyph\Omnibus\Serialization\UnknownStampType;
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

test('json serializer enforces payload stamp and structural bounds', function (): void {
    $serializer = new JsonEnvelopeSerializer(
        new MessageCodecRegistry([
            new CallbackMessageCodec(
                'test.command.v1',
                TestCommand::class,
                static fn(TestCommand $message): array => ['value' => $message->value],
                static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
            ),
        ]),
        new StampCodecRegistry(CoreStampCodecs::all()),
        maximumBytes: 256,
        maximumStamps: 2,
    );

    expect(fn() => $serializer->decode(''))
        ->toThrow(LengthException::class)
        ->and(fn() => $serializer->decode(str_repeat('x', 257)))
        ->toThrow(LengthException::class)
        ->and(fn() => $serializer->decode('[]'))
        ->toThrow(UnexpectedValueException::class)
        ->and(fn() => $serializer->decode(json_encode([
            'version' => 2,
            'message' => ['type' => 'test.command.v1', 'data' => []],
            'stamps' => [],
        ], JSON_THROW_ON_ERROR)))
        ->toThrow(UnexpectedValueException::class)
        ->and(fn() => $serializer->encode(new Envelope(new TestCommand(str_repeat('x', 300)))))
        ->toThrow(LengthException::class)
        ->and(fn() => $serializer->encode(new Envelope(new TestCommand('many'), [
            new MessageIdStamp('one'),
            new MessageIdStamp('two'),
            new MessageIdStamp('three'),
        ])))->toThrow(LengthException::class);
});

test('json serializer rejects unknown stamp aliases and non-scalar stamp data', function (): void {
    $unknown = json_encode([
        'version' => 1,
        'message' => ['type' => 'test.command.v1', 'data' => ['value' => 'x']],
        'stamps' => [['type' => 'unknown', 'data' => []]],
    ], JSON_THROW_ON_ERROR);
    $nested = json_encode([
        'version' => 1,
        'message' => ['type' => 'test.command.v1', 'data' => ['value' => 'x']],
        'stamps' => [['type' => 'message_id', 'data' => ['id' => ['nested']]]],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => omnibusSerializer()->decode($unknown))
        ->toThrow(UnknownStampType::class)
        ->and(fn() => omnibusSerializer()->decode($nested))
        ->toThrow(UnexpectedValueException::class);
});

test('codec registries reject duplicate aliases and types at construction', function (): void {
    $first = new CallbackMessageCodec(
        'duplicate',
        TestCommand::class,
        static fn(TestCommand $message): array => ['value' => $message->value],
        static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
    );
    $second = new CallbackMessageCodec(
        'duplicate',
        stdClass::class,
        static fn(stdClass $message): array => get_object_vars($message),
        static fn(array $data): stdClass => (object) $data,
    );
    $sameType = new CallbackMessageCodec(
        'other',
        TestCommand::class,
        static fn(TestCommand $message): array => ['value' => $message->value],
        static fn(array $data): TestCommand => new TestCommand((string) ($data['value'] ?? '')),
    );
    $wrongDecoder = new CallbackMessageCodec(
        'wrong',
        TestCommand::class,
        static fn(TestCommand $message): array => ['value' => $message->value],
        static fn(array $data): stdClass => (object) $data,
    );

    expect(fn() => new MessageCodecRegistry([$first, $second]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new MessageCodecRegistry([$first, $sameType]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $wrongDecoder->decode([]))
        ->toThrow(UnexpectedValueException::class)
        ->and(fn() => new JsonEnvelopeSerializer(
            new MessageCodecRegistry([$first]),
            new StampCodecRegistry(CoreStampCodecs::all()),
            maximumDepth: 1,
        ))->toThrow(InvalidArgumentException::class);
});

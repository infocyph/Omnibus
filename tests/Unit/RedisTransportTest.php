<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisTransport;
use Infocyph\Omnibus\Serialization\CallbackMessageCodec;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;

function omnibusRedisSerializer(): JsonEnvelopeSerializer
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

test('Redis transport uses atomic scripts for delivery lifecycle', function (): void {
    $payload = null;
    $commands = [];
    $client = new CallbackRedisClient(
        static function (string $command, string ...$arguments) use (&$payload, &$commands): mixed {
            $commands[] = [$command, $arguments[1] ?? null];
            $script = $arguments[0] ?? '';
            if (str_contains($script, 'HINCRBY')) {
                return ['row-1', $payload, '1'];
            }
            if (str_contains($script, 'HSET') && str_contains($script, 'ARGV[3]')) {
                $payload = $arguments[6] ?? null;

                return 1;
            }

            return 1;
        },
    );
    $transport = new RedisTransport(
        $client,
        omnibusRedisSerializer(),
        new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
    );

    $sent = $transport->send(new Envelope(new TestCommand('redis')), 'work');
    $reservation = [...$transport->receive('work')][0];
    $transport->release($reservation);
    $redelivery = [...$transport->receive('work')][0];
    $transport->acknowledge($redelivery);

    expect($sent->last(MessageIdStamp::class))->toBeInstanceOf(MessageIdStamp::class)
        ->and($reservation->envelope()->message)->toEqual(new TestCommand('redis'))
        ->and($reservation->attempt)->toBe(1)
        ->and($commands)->toHaveCount(5);
});

test('reservation receipts reject tampering', function (): void {
    $receipt = ReservationReceipt::encode('row-1', 'token-1');

    expect(ReservationReceipt::decode($receipt))->toBe(['row-1', 'token-1'])
        ->and(fn() => ReservationReceipt::decode($receipt.'x'))
        ->toThrow(InvalidReservation::class);
});

test('Redis transport rejects malformed and overflowing backend responses', function (mixed $response): void {
    $transport = new RedisTransport(
        new CallbackRedisClient(static fn(): mixed => $response),
        omnibusRedisSerializer(),
        new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
    );

    expect(fn() => $transport->size('work'))->toThrow(UnexpectedValueException::class);
})->with([
    'negative' => [-1],
    'fractional' => ['1.5'],
    'overflow' => [(string) PHP_INT_MAX.'0'],
    'non scalar' => [[]],
]);

test('Redis transport surfaces missing payload hash entries as poison reservations', function (): void {
    $transport = new RedisTransport(
        new CallbackRedisClient(static fn(): array => ['row-1', '', '1']),
        omnibusRedisSerializer(),
        new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
    );

    $reservation = [...$transport->receive('work')][0];

    expect($reservation->decodingFailure())->not->toBeNull()
        ->and($reservation->decodingFailure()?->payload)->toBe('');
});

test('Redis transport rejects malformed batches hash-tag collisions and excessive limits', function (): void {
    $transport = new RedisTransport(
        new CallbackRedisClient(static fn(): array => ['incomplete']),
        omnibusRedisSerializer(),
        new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
    );

    expect(fn() => [...$transport->receive('work')])
        ->toThrow(UnexpectedValueException::class)
        ->and(fn() => [...$transport->receive('work{other}')])
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => [...$transport->receive('work', 1_001)])
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RedisTransport(
            new CallbackRedisClient(static fn(): int => 1),
            omnibusRedisSerializer(),
            new FrozenClock(new DateTimeImmutable()),
            'prefix{collision}',
        ))->toThrow(InvalidArgumentException::class);
});

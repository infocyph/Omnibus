<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;

test('native Redis service completes reservation and settlement lifecycle', function (): void {
    if (!extension_loaded('redis') || !class_exists(Redis::class)) {
        test()->markTestSkipped('The redis extension is unavailable.');

        return;
    }
    $host = getenv('IC_REDIS_HOST');
    $port = getenv('IC_REDIS_PORT');
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        test()->markTestSkipped('The Redis service is not configured.');

        return;
    }

    $redis = new Redis();
    $redis->connect($host, (int) $port, 3);
    $password = getenv('IC_REDIS_PASSWORD');
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $prefix = 'omnibus_matrix_'.getmypid();
    $queue = 'native';
    $keys = [
        "{$prefix}:{native}:ready",
        "{$prefix}:{native}:reserved",
        "{$prefix}:{native}:payloads",
        "{$prefix}:{native}:attempts",
        "{$prefix}:{native}:receipts",
    ];
    $redis->del($keys);

    try {
        $transport = new RedisTransport(
            new CallbackRedisClient(
                static fn(string $command, string ...$arguments): mixed => $redis->rawCommand(
                    $command,
                    ...$arguments,
                ),
            ),
            TestSerializer::make(),
            new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            $prefix,
        );
        $transport->send(new Envelope(new TestCommand('redis-native')), $queue);
        $reservation = [...$transport->receive($queue)][0];
        $transport->release($reservation);
        $redelivery = [...$transport->receive($queue)][0];
        $transport->acknowledge($redelivery);

        expect($reservation->attempt)->toBe(1)
            ->and($redelivery->attempt)->toBe(2)
            ->and($redelivery->envelope()->message)->toEqual(new TestCommand('redis-native'))
            ->and($transport->size($queue))->toBe(0);
    } finally {
        $redis->del($keys);
        $redis->close();
    }
});

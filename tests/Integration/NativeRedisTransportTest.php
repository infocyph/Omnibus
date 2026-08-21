<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;

test('native Redis-compatible service completes reservation and settlement lifecycle', function (
    string $backend,
    string $hostVariable,
    string $portVariable,
    string $passwordVariable,
): void {
    if (!extension_loaded('redis') || !class_exists(Redis::class)) {
        test()->markTestSkipped('The redis extension is unavailable.');

        return;
    }
    $host = getenv($hostVariable);
    $port = getenv($portVariable);
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        test()->markTestSkipped(sprintf('The %s service is not configured.', $backend));

        return;
    }

    $redis = new Redis();
    $redis->connect($host, (int) $port, 3);
    $password = getenv($passwordVariable);
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $prefix = 'omnibus_'.$backend.'_matrix_'.getmypid();
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
        $transport->send(new Envelope(new TestCommand($backend.'-native')), $queue);
        $reservation = [...$transport->receive($queue)][0];
        $transport->release($reservation);
        $redelivery = [...$transport->receive($queue)][0];
        $transport->acknowledge($redelivery);

        expect($reservation->attempt)->toBe(1)
            ->and($redelivery->attempt)->toBe(2)
            ->and($redelivery->envelope()->message)->toEqual(new TestCommand($backend.'-native'))
            ->and($transport->size($queue))->toBe(0);
    } finally {
        $redis->del($keys);
        $redis->close();
    }
})->with([
    'redis' => ['redis', 'IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
    'valkey' => ['valkey', 'IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
]);

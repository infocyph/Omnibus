<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisBackendStateCorruption;
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
        throw new RuntimeException('The redis extension is required by this integration test.');
    }
    $host = getenv($hostVariable);
    $port = getenv($portVariable);
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        throw new RuntimeException(sprintf('The %s service must be configured for this integration test.', $backend));
    }

    $redis = new Redis();
    $redis->connect($host, (int) $port, 3);
    $password = getenv($passwordVariable);
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $prefix = 'omnibus_'.$backend.'_matrix_'.getmypid();
    $queue = 'native';
    $tag = sprintf('{%s:%s}', $prefix, $queue);
    $keys = [
        $tag . ':ready',
        $tag . ':reserved',
        $tag . ':payloads',
        $tag . ':attempts',
        $tag . ':receipts',
        $tag . ':message_ids',
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

        $transport->send(new Envelope(new TestCommand($backend.'-corrupt')), $queue);
        $ids = $redis->zRange($keys[0], 0, 0);
        $corruptId = is_array($ids) ? ($ids[0] ?? null) : null;
        if (!is_string($corruptId)) {
            throw new RuntimeException('Unable to locate the Redis corruption-test message.');
        }
        $redis->hDel($keys[2], $corruptId);

        expect(fn() => [...$transport->receive($queue)])
            ->toThrow(RedisBackendStateCorruption::class)
            ->and($redis->zScore($keys[0], $corruptId))->not->toBeFalse();
    } finally {
        $redis->del($keys);
        $redis->close();
    }
})->with([
    'redis' => ['redis', 'IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
    'valkey' => ['valkey', 'IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
]);

<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitBreakerScope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitOpen;
use Infocyph\Omnibus\Integration\CacheLayer\FixedWindowRateLimitScope;
use Infocyph\Omnibus\Integration\CacheLayer\RateLimitExceeded;
use Infocyph\Omnibus\Integration\Redis\CallbackRedisClient;
use Infocyph\Omnibus\Integration\Redis\RedisBackendStateCorruption;
use Infocyph\Omnibus\Integration\Redis\RedisTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\IntegrationEnvironment;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;

$omnibusRedisPolicyBackends = IntegrationEnvironment::redisBackendCases();
if ($omnibusRedisPolicyBackends === []) {
    return;
}

test('native Redis-compatible counters execute rate-limit and circuit-breaker policies', function (
    string $backend,
    string $hostVariable,
    string $portVariable,
    string $passwordVariable,
): void {
    $host = (string) getenv($hostVariable);
    $port = (string) getenv($portVariable);

    $redis = new Redis();
    $redis->connect($host, (int) $port, 3);
    $password = getenv($passwordVariable);
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $namespace = 'omnibus_'.$backend.'_policy_'.getmypid();
    $counters = AtomicCounters::redis($namespace, client: $redis);
    $locks = new RedisLockProvider($redis, $namespace.':locks:');
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $envelope = new Envelope(new TestCommand('policy'));
    $rate = new FixedWindowRateLimitScope(
        new DirectExecutionScope(),
        $counters,
        $clock,
        static fn(): string => 'tenant:42 with spaces',
        maximum: 1,
        windowSeconds: 60,
    );

    expect($rate->run($envelope, static fn(): string => 'allowed'))->toBe('allowed')
        ->and(fn() => $rate->run($envelope, static fn(): string => 'blocked'))
        ->toThrow(RateLimitExceeded::class);

    $circuit = new CircuitBreakerScope(
        new DirectExecutionScope(),
        $counters,
        $locks,
        $clock,
        static fn(): string => 'provider:billing',
        failureThreshold: 1,
        recoverySeconds: 2,
        failureWindowSeconds: 30,
    );

    expect(fn() => $circuit->run(
        $envelope,
        static fn() => throw new RuntimeException('provider unavailable'),
    ))->toThrow(RuntimeException::class, 'provider unavailable')
        ->and(fn() => $circuit->run($envelope, static fn(): string => 'blocked'))
        ->toThrow(CircuitOpen::class);

    $clock->advance('+3 seconds');

    expect($circuit->run($envelope, static fn(): string => 'recovered'))
        ->toBe('recovered');

    $redis->close();
})->with($omnibusRedisPolicyBackends);


test('native Redis-compatible receive preflight leaves corrupt state unchanged', function (
    string $backend,
    string $hostVariable,
    string $portVariable,
    string $passwordVariable,
): void {
    $redis = new Redis();
    $redis->connect((string) getenv($hostVariable), (int) getenv($portVariable), 3);
    $password = getenv($passwordVariable);
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $prefix = 'omnibus_' . $backend . '_transport_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $queue = 'work';
    $tag = sprintf('{%s:%s}', $prefix, $queue);
    $keys = [
        'ready' => $tag . ':ready',
        'reserved' => $tag . ':reserved',
        'payloads' => $tag . ':payloads',
        'attempts' => $tag . ':attempts',
        'receipts' => $tag . ':receipts',
        'message_ids' => $tag . ':message_ids',
    ];
    $client = new CallbackRedisClient(
        static fn(string $command, string ...$arguments): mixed => $redis->rawCommand($command, ...$arguments),
    );
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new RedisTransport($client, TestSerializer::make(), $clock, $prefix);
    $snapshot = static fn(): array => [
        'ready' => $redis->zRange($keys['ready'], 0, -1, true),
        'reserved' => $redis->zRange($keys['reserved'], 0, -1, true),
        'payloads' => $redis->hGetAll($keys['payloads']),
        'attempts' => $redis->hGetAll($keys['attempts']),
        'receipts' => $redis->hGetAll($keys['receipts']),
        'message_ids' => $redis->hGetAll($keys['message_ids']),
    ];
    $clear = static function () use ($redis, $keys): void {
        $redis->del(...array_values($keys));
    };

    try {
        foreach (['garbage', '-1', '9223372036854775807'] as $invalidAttempt) {
            $clear();
            $transport->send(new Envelope(new TestCommand('corrupt')), $queue);
            $transport->send(new Envelope(new TestCommand('neighbor')), $queue);
            $ids = $redis->zRange($keys['ready'], 0, -1);
            if (!is_array($ids) || count($ids) !== 2) {
                throw new RuntimeException('Expected two Redis queue records.');
            }
            $redis->hSet($keys['attempts'], (string) $ids[0], $invalidAttempt);
            $before = $snapshot();

            expect(fn() => [...$transport->receive($queue, 2)])
                ->toThrow(RedisBackendStateCorruption::class)
                ->and($snapshot())->toBe($before);
        }

        $clear();
        $transport->send(new Envelope(new TestCommand('boundary')), $queue);
        $ids = $redis->zRange($keys['ready'], 0, -1);
        if (!is_array($ids) || !isset($ids[0])) {
            throw new RuntimeException('Expected Redis boundary queue record.');
        }
        $redis->hSet($keys['attempts'], (string) $ids[0], '9223372036854775806');
        $boundary = [...$transport->receive($queue)][0];
        expect($boundary->attempt)->toBe(PHP_INT_MAX);
        $transport->acknowledge($boundary);

        $clear();
        $transport->send(new Envelope(new TestCommand('expired')), $queue);
        $reserved = [...$transport->receive($queue, visibilitySeconds: 1)][0];
        [$reservedId] = \Infocyph\Omnibus\Transport\ReservationReceipt::decode($reserved->receipt);
        $clock->advance('+2 seconds');
        $redis->hSet($keys['attempts'], $reservedId, 'garbage');
        $before = $snapshot();
        expect(fn() => [...$transport->receive($queue)])
            ->toThrow(RedisBackendStateCorruption::class)
            ->and($snapshot())->toBe($before);

        $clear();
        $redis->set($keys['ready'], 'wrong-type');
        expect(fn() => [...$transport->receive($queue)])
            ->toThrow(RedisBackendStateCorruption::class);
        expect($redis->get($keys['ready']))->toBe('wrong-type');
    } finally {
        $clear();
        $redis->close();
    }
})->with($omnibusRedisPolicyBackends);


test('native Redis-compatible circuit state fences an older success from a newer open', function (
    string $backend,
    string $hostVariable,
    string $portVariable,
    string $passwordVariable,
): void {
    $redis = new Redis();
    $redis->connect((string) getenv($hostVariable), (int) getenv($portVariable), 3);
    $password = getenv($passwordVariable);
    if (is_string($password) && $password !== '') {
        $redis->auth($password);
    }

    $namespace = 'omnibus_' . $backend . '_circuit_fence_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $scope = new CircuitBreakerScope(
        new DirectExecutionScope(),
        AtomicCounters::redis($namespace, client: $redis),
        new RedisLockProvider($redis, $namespace . ':locks:'),
        $clock,
        static fn(): string => 'provider:shared-generation',
        failureThreshold: 1,
        recoverySeconds: 30,
    );
    $envelope = new Envelope(new TestCommand('shared-generation'));

    try {
        expect($scope->run($envelope, function () use ($scope, $envelope): string {
            expect(fn() => $scope->run(
                $envelope,
                static fn() => throw new RuntimeException('newer shared failure'),
            ))->toThrow(RuntimeException::class, 'newer shared failure');

            return 'older shared success';
        }))->toBe('older shared success')
            ->and(fn() => $scope->run($envelope, static fn(): string => 'must remain open'))
            ->toThrow(CircuitOpen::class);
    } finally {
        $redis->close();
    }
})->with($omnibusRedisPolicyBackends);

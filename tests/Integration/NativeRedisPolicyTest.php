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
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;

test('native Redis counters execute rate-limit and circuit-breaker policies', function (): void {
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

    $namespace = 'omnibus_policy_'.getmypid();
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
});

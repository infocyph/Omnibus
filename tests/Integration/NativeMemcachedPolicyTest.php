<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitBreakerScope;
use Infocyph\Omnibus\Integration\CacheLayer\CircuitOpen;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;
use Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\InMemoryCounterStore;
use Infocyph\Omnibus\Tests\Fixtures\IntegrationEnvironment;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

if (!IntegrationEnvironment::memcachedConfigured()) {
    return;
}

test('native Memcached service preserves the unique-message lease lifecycle', function (): void {
    $host = (string) getenv('IC_MEMCACHED_HOST');
    $port = (string) getenv('IC_MEMCACHED_PORT');

    $memcached = new Memcached();
    $memcached->addServer($host, (int) $port);
    $probeKey = 'omnibus:probe:'.getmypid();
    $memcached->set($probeKey, 'ready', 5);
    if ($memcached->getResultCode() !== Memcached::RES_SUCCESS) {
        throw new RuntimeException('The configured Memcached service is unreachable.');
    }
    $memcached->delete($probeKey);

    $locks = new DetachedLeaseAdapter(new MemcachedLockProvider(
        $memcached,
        'omnibus:test:'.getmypid().':',
    ));
    $transport = new UniqueTransport(
        new InMemoryTransport(
            new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ),
        $locks,
    );
    $sender = new UniqueSender(
        $transport,
        $locks,
        static fn(Envelope $envelope): string => $envelope->message::class,
        leaseSeconds: 30,
        waitSeconds: 2,
    );

    $sender->send(new Envelope(new TestCommand('first')), 'work');
    expect(fn() => $sender->send(new Envelope(new TestCommand('duplicate')), 'work'))
        ->toThrow(DuplicateMessage::class);

    $reservation = [...$transport->receive('work')][0];
    $transport->acknowledge($reservation);

    expect($sender->send(new Envelope(new TestCommand('after-settlement')), 'work'))
        ->toBeInstanceOf(Envelope::class);

    $finalReservation = [...$transport->receive('work')][0];
    $transport->acknowledge($finalReservation);

    expect($transport->size('work'))->toBe(0);
});


test('native Memcached probe ownership preserves a newer circuit generation', function (): void {
    $memcached = new Memcached();
    $memcached->addServer((string) getenv('IC_MEMCACHED_HOST'), (int) getenv('IC_MEMCACHED_PORT'));

    $namespace = 'omnibus:circuit:' . getmypid() . ':' . bin2hex(random_bytes(4)) . ':';
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $scope = new CircuitBreakerScope(
        new DirectExecutionScope(),
        new InMemoryCounterStore($clock),
        new MemcachedLockProvider($memcached, $namespace),
        $clock,
        static fn(): string => 'provider:memcached-fence',
        failureThreshold: 1,
        recoverySeconds: 30,
    );
    $envelope = new Envelope(new TestCommand('memcached-fence'));

    expect($scope->run($envelope, function () use ($scope, $envelope): string {
        expect(fn() => $scope->run(
            $envelope,
            static fn() => throw new RuntimeException('newer memcached failure'),
        ))->toThrow(RuntimeException::class, 'newer memcached failure');

        return 'older memcached success';
    }))->toBe('older memcached success')
        ->and(fn() => $scope->run($envelope, static fn(): string => 'must remain open'))
        ->toThrow(CircuitOpen::class);

    $memcached->quit();
});

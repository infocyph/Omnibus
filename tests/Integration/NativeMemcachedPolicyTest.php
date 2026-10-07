<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;
use Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
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

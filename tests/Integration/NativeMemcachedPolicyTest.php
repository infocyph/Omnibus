<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\CacheLayer\DetachedLeaseAdapter;
use Infocyph\Omnibus\Integration\CacheLayer\DuplicateMessage;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueSender;
use Infocyph\Omnibus\Integration\CacheLayer\UniqueTransport;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('native Memcached service preserves the unique-message lease lifecycle', function (): void {
    if (!extension_loaded('memcached') || !class_exists(Memcached::class)) {
        test()->markTestSkipped('The memcached extension is unavailable.');

        return;
    }
    $host = getenv('IC_MEMCACHED_HOST');
    $port = getenv('IC_MEMCACHED_PORT');
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        test()->markTestSkipped('The Memcached service is not configured.');

        return;
    }

    $memcached = new Memcached();
    $memcached->addServer($host, (int) $port);
    $probeKey = 'omnibus:probe:'.getmypid();
    $memcached->set($probeKey, 'ready', 5);
    if ($memcached->getResultCode() !== Memcached::RES_SUCCESS) {
        test()->markTestSkipped('The Memcached service is unreachable.');

        return;
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

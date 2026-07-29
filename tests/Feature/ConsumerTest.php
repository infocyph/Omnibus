<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('consumer acknowledges successful messages', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $handled = [];
    $transport->send(
        new Envelope(new TestCommand('one'), [new MessageIdStamp('message-1')]),
        'default',
    );
    $consumer = new Consumer(
        $transport,
        new HandlerMap([
            TestCommand::class => static function (TestCommand $message) use (&$handled): void {
                $handled[] = $message->value;
            },
        ]),
        new ExponentialRetryStrategy(initialDelaySeconds: 0),
        new InMemoryFailureStore(),
        $clock,
    );

    $result = $consumer->run();

    expect($result->succeeded)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($transport->size('default'))->toBe(0)
        ->and($handled)->toBe(['one']);
});

test('consumer retries transient failures and records terminal failures', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $failures = new InMemoryFailureStore();
    $transport->send(
        new Envelope(new TestCommand('fail'), [new MessageIdStamp('message-2')]),
        'default',
    );
    $consumer = new Consumer(
        $transport,
        new HandlerMap([
            TestCommand::class => static fn() => throw new RuntimeException('broken'),
        ]),
        new ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: 0),
        $failures,
        $clock,
    );

    $first = $consumer->run();
    $second = $consumer->run();

    expect($first->released)->toBe(1)
        ->and($second->failed)->toBe(1)
        ->and($transport->size('default'))->toBe(0)
        ->and($failures->find('message-2')?->reason)->toBe('broken')
        ->and($failures->find('message-2')?->attempt)->toBe(2);
});

test('expired reservations become available for redelivery', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('restore')), 'default');

    $reservations = [...$transport->receive('default', visibilitySeconds: 2)];
    expect($reservations)->toHaveCount(1)
        ->and($transport->size('default'))->toBe(0);

    $clock->advance('+3 seconds');

    expect($transport->size('default'))->toBe(1)
        ->and([...$transport->receive('default')][0]->attempt)->toBe(2);
});

<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InMemoryTransport;

it('defers failure storage during empty successful and retryable consumption', function (): void {
    $clock = new SystemClock();
    $transport = new InMemoryTransport($clock);
    $failures = new InMemoryFailureStore($clock);
    $calls = 0;
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([stdClass::class => static function (stdClass $message): void {
            if ($message->fail ?? false) {
                throw new RuntimeException('handler failed');
            }
        }])),
        new ExponentialRetryStrategy(maximumAttempts: 2, initialDelaySeconds: 0),
        null,
        $clock,
        failureStoreResolver: static function () use (&$calls, $failures): FailureStore {
            ++$calls;
            return $failures;
        },
    );
    expect($calls)->toBe(0)->and($consumer->run()->received)->toBe(0)->and($calls)->toBe(0);
    $transport->send(Envelope::wrap(new stdClass()), 'default');
    expect($consumer->run()->succeeded)->toBe(1)->and($calls)->toBe(0);
    $transport->send(new Envelope((object) ['fail' => true], [new MessageIdStamp('deferred-terminal')]), 'default');
    expect($consumer->run()->released)->toBe(1)->and($calls)->toBe(0);
    expect($consumer->run()->failed)->toBe(1)->and($calls)->toBe(1)
        ->and($failures->find('deferred-terminal')?->reason)->toBe('handler failed');
});

it('propagates failure-store factory failures without rejecting the reservation', function (): void {
    $clock = new SystemClock();
    $transport = new InMemoryTransport($clock);
    $transport->send(Envelope::wrap(new stdClass()), 'default');
    $failure = new RuntimeException('failure backend unavailable');
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([stdClass::class => static fn() => throw new RuntimeException('handler failed')])),
        new ExponentialRetryStrategy(maximumAttempts: 1),
        null,
        $clock,
        failureStoreResolver: static fn(): FailureStore => throw $failure,
    );
    expect(fn() => $consumer->run(visibilitySeconds: 0.01))->toThrow($failure);
    usleep(20000);
    expect(iterator_to_array($transport->receive('default')))->toHaveCount(1);
});

it('rejects an invalid deferred failure store on terminal failure', function (): void {
    $clock = new SystemClock();
    $transport = new InMemoryTransport($clock);
    $transport->send(Envelope::wrap(new stdClass()), 'default');
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([stdClass::class => static fn() => throw new RuntimeException('handler failed')])),
        new ExponentialRetryStrategy(maximumAttempts: 1),
        null,
        $clock,
        failureStoreResolver: static fn(): stdClass => new stdClass(),
    );
    expect(fn() => $consumer->run())->toThrow(TypeError::class);
});

it('keeps the failure-store dependency discoverable by existing constructor autowiring', function (): void {
    $type = new ReflectionClass(Consumer::class)->getConstructor()->getParameters()[3]->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    expect($type->getName())->toBe(FailureStore::class);
});

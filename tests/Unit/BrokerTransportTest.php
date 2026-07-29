<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\AMQP\AmqpTransport;
use Infocyph\Omnibus\Integration\Broker\BrokerCapabilities;
use Infocyph\Omnibus\Integration\Broker\BrokerDelivery;
use Infocyph\Omnibus\Integration\Broker\UnsupportedBrokerCapability;
use Infocyph\Omnibus\Tests\Fixtures\RecordingBrokerBackend;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Tests\Fixtures\TestSerializer;

test('broker transport preserves native batch and settlement boundaries', function (): void {
    $backend = new RecordingBrokerBackend(new BrokerCapabilities(true, true, false, 10));
    $serializer = TestSerializer::make();
    $transport = new AmqpTransport($backend, $serializer);
    $transport->send(
        new Envelope(new TestCommand('broker'), [new DelayStamp(2)]),
        'work',
    );
    $backend->deliveries = [
        new BrokerDelivery('delivery-1', $backend->sent[0]['payload'], 3),
    ];

    $reservation = [...$transport->receive('work', 10)][0];
    $transport->release($reservation, 1);
    $transport->acknowledge($reservation);

    expect($backend->sent[0]['delay'])->toBe(2.0)
        ->and($reservation->attempt)->toBe(3)
        ->and($reservation->envelope()->message)->toEqual(new TestCommand('broker'))
        ->and($backend->settled)->toBe([
            'release:work:delivery-1:1.0',
            'ack:work:delivery-1',
        ]);
});

test('broker transport rejects capabilities the selected provider does not offer', function (): void {
    $backend = new RecordingBrokerBackend(new BrokerCapabilities(false, false, true, 1));
    $transport = new AmqpTransport($backend, TestSerializer::make());

    expect(fn() => $transport->send(
        new Envelope(new TestCommand('delayed'), [new DelayStamp(1)]),
        'work',
    ))->toThrow(UnsupportedBrokerCapability::class)
        ->and(fn() => [...$transport->receive('work', 2)])
        ->toThrow(InvalidArgumentException::class);
});

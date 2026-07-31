<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\ConsumerResult;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\Duration;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;
use Infocyph\Omnibus\Workflow\WorkflowState;
use Infocyph\Omnibus\Workflow\WorkflowStatus;

test('queue names are bounded consistently at public boundaries', function (): void {
    QueueName::assert('billing.high-priority');

    foreach (['', str_repeat('q', 192), "queue\nname"] as $invalid) {
        expect(fn() => QueueName::assert($invalid))->toThrow(InvalidArgumentException::class);
        expect(fn() => new Route('sync', $invalid))->toThrow(InvalidArgumentException::class);
    }
});

test('durations reject overflow before timestamp arithmetic', function (): void {
    expect(Duration::microseconds(1.25))->toBe(1_250_000)
        ->and(fn() => Duration::microseconds(INF))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => Duration::microseconds((float) PHP_INT_MAX, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('reservation result failure and workflow invariants reject corrupt state', function (): void {
    expect(fn() => Reservation::decoded('', 'work', new Envelope(new TestCommand('x')), 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ConsumerResult(2, 1, 0, 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => FailedMessage::undecodable(
            str_repeat('i', 192),
            'work',
            'raw',
            1,
            new DateTimeImmutable(),
            RuntimeException::class,
            'reason',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new WorkflowState(
            'id',
            'batch',
            WorkflowStatus::Completed,
            1,
            1,
            1,
            0,
        ))->toThrow(InvalidArgumentException::class);
});

test('serialized identifiers and receipts are bounded before decoding', function (): void {
    expect(fn() => new Infocyph\Omnibus\Envelope\MessageIdStamp(str_repeat('m', 192)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new Infocyph\Omnibus\Envelope\RouteStamp("sync\n", 'work'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => ReservationReceipt::decode(str_repeat('x', 1_025)))
        ->toThrow(Infocyph\Omnibus\Transport\InvalidReservation::class)
        ->and(fn() => ReservationReceipt::encode(str_repeat('i', 513), 'token'))
        ->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use Infocyph\Omnibus\Testing\RecordingSender;
use Infocyph\Omnibus\Transport\TransportNotFound;
use Infocyph\Omnibus\Transport\TransportRegistry;

it('constructs only the selected transport and preserves existing sender identity', function (): void {
    $sender = new RecordingSender();
    $calls = 0;
    $registry = new TransportRegistry([
        'ready' => $sender,
        'deferred' => static function () use (&$calls): RecordingSender {
            ++$calls;
            return new RecordingSender();
        },
    ]);

    expect($calls)->toBe(0)
        ->and($registry->get('ready'))->toBe($sender)
        ->and($calls)->toBe(0);
    expect(fn() => $registry->get('missing'))->toThrow(TransportNotFound::class);
    expect($calls)->toBe(0);
    expect($registry->get('deferred'))->toBeInstanceOf(RecordingSender::class)
        ->and($calls)->toBe(1);
});

it('leaves deferred transport lifetime with the supplied factory', function (): void {
    $calls = 0;
    $registry = new TransportRegistry(['scoped' => static function () use (&$calls): RecordingSender {
        ++$calls;
        return new RecordingSender();
    }]);
    $first = $registry->get('scoped');
    $second = $registry->get('scoped');

    expect($first)->not->toBe($second)->and($calls)->toBe(2);
});

it('does not poison a deferred transport after a factory failure', function (): void {
    $calls = 0;
    $failure = new RuntimeException('backend unavailable');
    $sender = new RecordingSender();
    $registry = new TransportRegistry(['retry' => static function () use (&$calls, $failure, $sender): RecordingSender {
        if (++$calls === 1) {
            throw $failure;
        }
        return $sender;
    }]);

    expect(fn() => $registry->get('retry'))->toThrow($failure)
        ->and($registry->get('retry'))->toBe($sender)
        ->and($calls)->toBe(2);
});

it('rejects an invalid deferred transport result when it is selected', function (): void {
    $registry = new TransportRegistry(['invalid' => static fn(): stdClass => new stdClass()]);
    expect(fn() => $registry->get('invalid'))->toThrow(TypeError::class);
});

it('validates deferred transport names without calling factories', function (mixed $name): void {
    $factory = static function (): RecordingSender {
        throw new RuntimeException('Invalid transport names must be rejected before resolution.');
    };
    expect(fn() => new TransportRegistry([$name => $factory]))->toThrow(InvalidArgumentException::class);
})->with(['', '0', "bad\nname", str_repeat('a', 101)]);

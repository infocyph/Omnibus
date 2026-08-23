<?php

declare(strict_types=1);

use Infocyph\Omnibus\Handler\HandlerContext;

test('handler context preserves valid synchronous and asynchronous delivery metadata', function (): void {
    $sync = new HandlerContext('default');
    $async = new HandlerContext('billing', 3, true);

    expect($sync->queue)->toBe('default')
        ->and($sync->attempt)->toBe(1)
        ->and($sync->asynchronous)->toBeFalse()
        ->and($async->queue)->toBe('billing')
        ->and($async->attempt)->toBe(3)
        ->and($async->asynchronous)->toBeTrue()
        ->and((new ReflectionClass(HandlerContext::class))->isReadOnly())->toBeTrue();
});

test('handler context rejects invalid delivery metadata', function (): void {
    expect(fn() => new HandlerContext(''))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new HandlerContext('work', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new HandlerContext('work', -1))->toThrow(InvalidArgumentException::class);
});

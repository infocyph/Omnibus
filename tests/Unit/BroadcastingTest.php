<?php

declare(strict_types=1);

use Infocyph\Omnibus\Broadcasting\Broadcast;
use Infocyph\Omnibus\Broadcasting\BroadcastHandler;
use Infocyph\Omnibus\Broadcasting\Broadcaster;
use Infocyph\Omnibus\Broadcasting\Channel;

test('broadcast handler delegates a bounded provider-neutral message', function (): void {
    $sent = [];
    $broadcaster = new class($sent) implements Broadcaster {
        /** @param list<Broadcast> $sent */
        public function __construct(private array &$sent) {}

        public function broadcast(Broadcast $broadcast): void
        {
            $this->sent[] = $broadcast;
        }
    };
    $message = new Broadcast(
        'order.updated',
        [new Channel('orders.42', private: true)],
        ['status' => 'paid'],
    );

    (new BroadcastHandler($broadcaster))($message);

    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toBe($message);
});

test('presence channels cannot be public', function (): void {
    expect(fn() => new Channel('presence.orders', presence: true))
        ->toThrow(InvalidArgumentException::class);
});

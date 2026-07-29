<?php

declare(strict_types=1);

namespace Infocyph\Omnibus;

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\UID\ULID;

final readonly class MessageBus
{
    public function __construct(
        private RouteMap $routes,
        private TransportRegistry $transports,
    ) {}

    public function dispatch(object $message): Envelope
    {
        $envelope = Envelope::wrap($message);
        if ($envelope->last(MessageIdStamp::class) === null) {
            $envelope = $envelope->with(new MessageIdStamp(ULID::generateMonotonic()));
        }

        $route = $this->routes->for($envelope->message);
        $envelope = $envelope->with(new RouteStamp($route->transport, $route->queue));
        if ($route->delaySeconds > 0.0 && $envelope->last(DelayStamp::class) === null) {
            $envelope = $envelope->with(new DelayStamp($route->delaySeconds));
        }

        return $this->transports->get($route->transport)->send($envelope, $route->queue);
    }
}

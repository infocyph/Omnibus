<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerMap;

final readonly class SyncTransport implements Sender
{
    public function __construct(private HandlerMap $handlers) {}

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $delay = $envelope->last(DelayStamp::class);
        if ($delay instanceof DelayStamp && $delay->seconds > 0.0) {
            throw new UnsupportedDelay('SyncTransport cannot honor a positive delivery delay.');
        }

        $handler = $this->handlers->for($envelope->message);

        return $envelope->with(new HandledStamp($handler($envelope->message, $envelope, $queue)));
    }
}

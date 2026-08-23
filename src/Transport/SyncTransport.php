<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerInvoker;

final readonly class SyncTransport implements Sender
{
    public function __construct(private HandlerInvoker $invoker) {}

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $delay = $envelope->last(DelayStamp::class);
        if ($delay instanceof DelayStamp && $delay->seconds > 0.0) {
            throw new UnsupportedDelay('SyncTransport cannot honor a positive delivery delay.');
        }

        $context = new HandlerContext(queue: $queue);
        $result = $this->invoker->invoke($envelope->message, $envelope, $context);

        return $envelope->with(new HandledStamp($result));
    }
}

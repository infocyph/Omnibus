<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Handler;

use Infocyph\Omnibus\Envelope\Envelope;

interface HandlerMiddleware
{
    /** @param callable(object, Envelope, HandlerContext): mixed $next */
    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed;
}

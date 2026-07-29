<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Envelope;

final class DirectExecutionScope implements ExecutionScope
{
    public function run(Envelope $envelope, callable $handler): mixed
    {
        return $handler($envelope->message, $envelope);
    }
}

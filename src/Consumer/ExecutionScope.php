<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Envelope;

interface ExecutionScope
{
    public function run(Envelope $envelope, callable $handler): mixed;
}

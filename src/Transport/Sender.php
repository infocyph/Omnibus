<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

use Infocyph\Omnibus\Envelope\Envelope;

interface Sender
{
    public function send(Envelope $envelope, string $queue): Envelope;
}

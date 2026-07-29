<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Envelope;

interface EnvelopeSerializer
{
    public function decode(string $payload): Envelope;

    public function encode(Envelope $envelope): string;
}

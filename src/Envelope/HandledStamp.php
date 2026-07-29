<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class HandledStamp implements Stamp
{
    public function __construct(public mixed $result) {}
}

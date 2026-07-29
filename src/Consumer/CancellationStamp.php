<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Stamp;

final readonly class CancellationStamp implements Stamp
{
    public function __construct(public CancellationToken $token) {}
}

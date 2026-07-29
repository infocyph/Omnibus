<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

final readonly class TestEvent
{
    public function __construct(public string $value)
    {
    }
}

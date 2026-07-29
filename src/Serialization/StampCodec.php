<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Stamp;

interface StampCodec
{
    public function alias(): string;

    /** @param array<string, bool|float|int|string|null> $payload */
    public function decode(array $payload): Stamp;

    /** @return array<string, bool|float|int|string|null> */
    public function encode(Stamp $stamp): array;

    /** @return class-string<Stamp> */
    public function type(): string;
}

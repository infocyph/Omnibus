<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

interface MessageCodec
{
    public function alias(): string;

    /** @param array<string, mixed> $payload */
    public function decode(array $payload): object;

    /** @return array<string, mixed> */
    public function encode(object $message): array;

    /** @return class-string */
    public function type(): string;
}

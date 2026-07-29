<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class MessageIdStamp implements Stamp
{
    public function __construct(public string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A message identifier cannot be empty.');
        }
    }
}

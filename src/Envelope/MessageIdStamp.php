<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class MessageIdStamp implements Stamp
{
    public function __construct(public string $id)
    {
        if (
            $id === ''
            || strlen($id) > 191
            || preg_match('/[\x00-\x1F\x7F]/D', $id) === 1
        ) {
            throw new \InvalidArgumentException(
                'A message identifier must contain between 1 and 191 bytes without control characters.',
            );
        }
    }
}

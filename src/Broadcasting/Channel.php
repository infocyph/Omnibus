<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Broadcasting;

final readonly class Channel
{
    public function __construct(
        public string $name,
        public bool $private = false,
        public bool $presence = false,
    ) {
        if (
            $name === ''
            || strlen($name) > 200
            || preg_match('/[\x00-\x1F\x7F]/D', $name) === 1
        ) {
            throw new \InvalidArgumentException(
                'Channel names must contain between 1 and 200 bytes without control characters.',
            );
        }
        if ($presence && !$private) {
            throw new \InvalidArgumentException('Presence channels must also be private.');
        }
    }
}
